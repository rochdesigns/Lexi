<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\DebtPayment;
use App\Models\Goal;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes every figure shown on the financial trajectory report.
 *
 * Each public method takes the user explicitly — the service holds no per-user state.
 * When the underlying data is missing a method returns ['available' => false, 'reason' => '…']
 * rather than zeros that would read as a real (and alarming) result.
 */
class TrajectoryReportService
{
    /**
     * @return array<string, mixed>
     */
    public function netWorth(User $user): array
    {
        $linkedCreditIds = $user->accounts()
            ->where('type', 'credit')
            ->whereHas('linkedDebt')
            ->pluck('id');

        $accountTotal = round((float) $user->accounts()->whereNotIn('id', $linkedCreditIds)->sum('balance'), 2);
        $debtTotal = round((float) $user->debts()->sum('balance'), 2);
        $current = round($accountTotal - $debtTotal, 2);

        $windowStart = Carbon::now()->startOfMonth()->subMonths(11);

        $accountByMonth = $this->lastBalancePerMonth(
            AccountBalanceSnapshot::where('user_id', $user->id)
                ->whereNotIn('account_id', $linkedCreditIds)
                ->where('snapshot_date', '>=', $windowStart->toDateString())
                ->orderBy('snapshot_date')
                ->get(),
            'account_id',
        );

        $debtByMonth = $this->lastBalancePerMonth(
            DebtBalanceSnapshot::where('user_id', $user->id)
                ->where('snapshot_date', '>=', $windowStart->toDateString())
                ->orderBy('snapshot_date')
                ->get(),
            'debt_id',
        );

        $hasDebts = $user->debts()->exists();

        $trend = [];

        foreach ($this->monthWindow(12) as $month) {
            $key = $month->format('Y-m');

            if (! array_key_exists($key, $accountByMonth)) {
                continue;
            }

            // A user with debts but no debt snapshot for this month has unknown
            // (not zero) debt for that month — e.g. backfill can decline to write
            // a debt's history when it doesn't reconcile within tolerance. Only a
            // user with no debts at all can safely treat missing as zero.
            if ($hasDebts && ! array_key_exists($key, $debtByMonth)) {
                continue;
            }

            $trend[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'value' => round($accountByMonth[$key] - ($debtByMonth[$key] ?? 0.0), 2),
            ];
        }

        $headline = [
            'current' => $current,
            'accountTotal' => $accountTotal,
            'debtTotal' => $debtTotal,
        ];

        if (count($trend) < 2) {
            return [
                'available' => false,
                'reason' => 'Not enough balance history yet — net worth trend needs snapshots from at least two months.',
                ...$headline,
                'trend' => [],
            ];
        }

        $first = $trend[0]['value'];
        $last = $trend[count($trend) - 1]['value'];

        return [
            'available' => true,
            ...$headline,
            'trend' => $trend,
            'changeAmount' => round($last - $first, 2),
            'changePct' => $first != 0.0 ? round(($last - $first) / abs($first) * 100, 1) : null,
        ];
    }

    /**
     * The last `$count` calendar months, oldest first, including the current month.
     *
     * @return list<Carbon>
     */
    protected function monthWindow(int $count): array
    {
        $months = [];

        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $months[] = Carbon::now()->startOfMonth()->subMonths($offset);
        }

        return $months;
    }

    /**
     * @return array<string, mixed>
     */
    public function savingsRate(User $user): array
    {
        $months = $this->monthWindow(6);
        $trend = [];
        $hasActivity = false;

        foreach ($months as $month) {
            $totals = $this->monthlyTotals($user, $month);

            if ($totals['income'] > 0 || $totals['expenses'] > 0) {
                $hasActivity = true;
            }

            $trend[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'income' => $totals['income'],
                'expenses' => $totals['expenses'],
                'rate' => $totals['income'] > 0
                    ? round(($totals['income'] - $totals['expenses']) / $totals['income'] * 100, 1)
                    : null,
            ];
        }

        if (! $hasActivity) {
            return [
                'available' => false,
                'reason' => 'No income or spending recorded in the last six months.',
            ];
        }

        $current = $trend[count($trend) - 1];
        $rates = array_values(array_filter(array_column($trend, 'rate'), fn ($rate) => $rate !== null));

        return [
            'available' => true,
            'income' => $current['income'],
            'expenses' => $current['expenses'],
            'net' => round($current['income'] - $current['expenses'], 2),
            'rate' => $current['rate'],
            'trend' => $trend,
            'averageRate' => $rates !== [] ? round(array_sum($rates) / count($rates), 1) : null,
        ];
    }

    /**
     * Income and expense totals for one calendar month.
     *
     * @return array{income: float, expenses: float}
     */
    protected function monthlyTotals(User $user, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        $rows = $user->transactions()
            ->whereBetween('transacted_at', [$start, $end])
            ->whereIn('type', ['income', 'expense'])
            ->get(['type', 'amount']);

        return [
            'income' => round((float) $rows->where('type', 'income')->sum('amount'), 2),
            'expenses' => round((float) $rows->where('type', 'expense')->sum('amount'), 2),
        ];
    }

    /**
     * Sum of each owner's latest snapshot within each calendar month.
     *
     * @param  Collection<int, AccountBalanceSnapshot|DebtBalanceSnapshot>  $snapshots
     * @param  'account_id'|'debt_id'  $ownerKey
     * @return array<string, float>
     */
    private function lastBalancePerMonth(Collection $snapshots, string $ownerKey): array
    {
        $latest = [];

        foreach ($snapshots as $snapshot) {
            $month = $snapshot->snapshot_date->format('Y-m');
            $owner = $snapshot->{$ownerKey};
            $date = $snapshot->snapshot_date->toDateString();

            if (! isset($latest[$month][$owner]) || $latest[$month][$owner]['date'] < $date) {
                $latest[$month][$owner] = ['date' => $date, 'balance' => (float) $snapshot->balance];
            }
        }

        $totals = [];

        foreach ($latest as $month => $owners) {
            $totals[$month] = round(array_sum(array_column($owners, 'balance')), 2);
        }

        return $totals;
    }

    /**
     * Generalized amortization simulation, extracted from ReportController::computePayoff().
     *
     * Each active debt pays its own perMonth every month; the whole $extraMonthly is applied
     * avalanche-style to the highest-rate active debt, re-chosen each month. A debt whose
     * allocated payment cannot cover its own accrued interest is dropped as not-on-track.
     *
     * @param  list<array{balance: float, perMonth: float, monthlyRate: float}>  $debts
     * @return array{months: int|null, onTrack: bool, perDebtMonths: array<int, int|null>}
     */
    public function simulatePayoff(array $debts, float $extraMonthly = 0.0): array
    {
        $state = [];

        foreach ($debts as $key => $debt) {
            $state[$key] = [
                'balance' => (float) $debt['balance'],
                'perMonth' => (float) $debt['perMonth'],
                'monthlyRate' => (float) $debt['monthlyRate'],
                'months' => (float) $debt['balance'] <= 0 ? 0 : null,
                'onTrack' => true,
            ];
        }

        $months = 0;

        while ($months < 600) {
            $active = array_keys(array_filter($state, fn (array $debt) => $debt['onTrack'] && $debt['balance'] > 0));

            if ($active === []) {
                break;
            }

            usort($active, fn (int $a, int $b) => $state[$b]['monthlyRate'] <=> $state[$a]['monthlyRate']);
            $target = $active[0];
            $months++;

            foreach ($active as $key) {
                $payment = $state[$key]['perMonth'] + ($key === $target ? $extraMonthly : 0.0);
                $interest = $state[$key]['balance'] * $state[$key]['monthlyRate'];

                if ($payment <= $interest) {
                    $state[$key]['onTrack'] = false;

                    continue;
                }

                $state[$key]['balance'] = $state[$key]['balance'] + $interest - $payment;

                if ($state[$key]['balance'] <= 0) {
                    $state[$key]['balance'] = 0.0;
                    $state[$key]['months'] = $months;
                }
            }
        }

        $perDebtMonths = [];
        $allCleared = true;
        $longest = 0;

        foreach ($state as $key => $debt) {
            $cleared = $debt['onTrack'] && $debt['months'] !== null;
            $perDebtMonths[$key] = $cleared ? $debt['months'] : null;

            if (! $cleared) {
                $allCleared = false;

                continue;
            }

            $longest = max($longest, (int) $debt['months']);
        }

        return [
            'months' => $allCleared ? $longest : null,
            'onTrack' => $allCleared,
            'perDebtMonths' => $perDebtMonths,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function debtSummary(User $user, ?float $extraMonthly = null): array
    {
        $debts = $user->debts()->where('balance', '>', 0)->orderBy('position')->orderBy('id')->get();

        if ($debts->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'No outstanding debts — nothing is accruing interest right now.',
            ];
        }

        $extra = max(0.0, (float) ($extraMonthly ?? 0.0));
        $today = Carbon::now()->startOfDay();

        $sorted = $debts
            ->sortByDesc(fn (Debt $debt) => $debt->interest_rate === null ? -1.0 : (float) $debt->interest_rate)
            ->values();

        $inputs = $sorted->map(fn (Debt $debt) => [
            'balance' => (float) $debt->balance,
            'perMonth' => (float) $debt->per_month,
            'monthlyRate' => $this->monthlyRate($debt),
        ])->all();

        $baseline = $this->simulatePayoff($inputs, 0.0);
        $projected = $extra > 0.0 ? $this->simulatePayoff($inputs, $extra) : $baseline;

        $rows = $sorted->values()->map(function (Debt $debt, int $index) use ($projected, $today) {
            $months = $projected['perDebtMonths'][$index] ?? null;

            return [
                'id' => $debt->id,
                'name' => $debt->name,
                'icon' => $debt->icon,
                'color' => $debt->color,
                'bg' => $debt->bg,
                'balance' => round((float) $debt->balance, 2),
                'perMonth' => round((float) $debt->per_month, 2),
                'interestRate' => $debt->interest_rate !== null ? (float) $debt->interest_rate : null,
                'interestRatePeriod' => $debt->interest_rate_period ?? 'annual',
                'months' => $months,
                'payoffDate' => $months !== null ? $today->copy()->addMonthsNoOverflow($months)->format('M Y') : null,
            ];
        })->all();

        $thisMonth = Carbon::now()->startOfMonth();
        $paymentsThisMonth = $this->debtPaymentTotals($user, $thisMonth);

        $interestYearToDate = round((float) DebtPayment::where('user_id', $user->id)
            ->whereBetween('paid_at', [Carbon::now()->startOfYear()->toDateString(), Carbon::now()->endOfYear()->toDateString()])
            ->sum('interest_amount'), 2);

        $splitTrend = [];

        foreach ($this->monthWindow(12) as $month) {
            $totals = $this->debtPaymentTotals($user, $month);
            $splitTrend[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'principal' => $totals['principal'],
                'interest' => $totals['interest'],
            ];
        }

        $scheduledMonthly = round((float) $debts->sum('per_month'), 2);
        $dtiTrend = [];

        foreach ($this->monthWindow(6) as $month) {
            $dtiTrend[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'ratio' => $this->debtToIncomeRatio($user, $month, $scheduledMonthly),
            ];
        }

        $currentIncome = $this->monthlyTotals($user, $thisMonth)['income'];
        $numerator = $paymentsThisMonth['amount'] > 0 ? $paymentsThisMonth['amount'] : $scheduledMonthly;

        return [
            'available' => true,
            'totalRemaining' => round((float) $debts->sum('balance'), 2),
            'totalMonthly' => $scheduledMonthly,
            'extraMonthly' => round($extra, 2),
            'debts' => $rows,
            'debtFreeMonths' => $projected['months'],
            'debtFreeDate' => $projected['months'] !== null
                ? $today->copy()->addMonthsNoOverflow($projected['months'])->format('M Y')
                : null,
            'onTrack' => $projected['onTrack'],
            'baselineDebtFreeMonths' => $baseline['months'],
            'monthsSaved' => $baseline['months'] !== null && $projected['months'] !== null
                ? $baseline['months'] - $projected['months']
                : null,
            'interestThisMonth' => $paymentsThisMonth['interest'],
            'interestYearToDate' => $interestYearToDate,
            'principalInterestTrend' => $splitTrend,
            'debtToIncome' => [
                'ratio' => $this->debtToIncomeRatio($user, $thisMonth, $scheduledMonthly),
                'monthlyDebtPayments' => round($numerator, 2),
                'income' => $currentIncome,
                'isEstimated' => $paymentsThisMonth['amount'] <= 0,
                'trend' => $dtiTrend,
            ],
        ];
    }

    protected function monthlyRate(Debt $debt): float
    {
        if ($debt->interest_rate === null) {
            return 0.0;
        }

        return $debt->interest_rate_period === 'monthly'
            ? (float) $debt->interest_rate / 100
            : (float) $debt->interest_rate / 100 / 12;
    }

    /**
     * @return array{amount: float, principal: float, interest: float}
     */
    protected function debtPaymentTotals(User $user, Carbon $month): array
    {
        $rows = DebtPayment::where('user_id', $user->id)
            ->whereBetween('paid_at', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->get(['amount', 'principal_amount', 'interest_amount']);

        return [
            'amount' => round((float) $rows->sum('amount'), 2),
            'principal' => round((float) $rows->sum('principal_amount'), 2),
            'interest' => round((float) $rows->sum('interest_amount'), 2),
        ];
    }

    protected function debtToIncomeRatio(User $user, Carbon $month, float $scheduledMonthly): ?float
    {
        $income = $this->monthlyTotals($user, $month)['income'];

        if ($income <= 0) {
            return null;
        }

        $paid = $this->debtPaymentTotals($user, $month)['amount'];
        $numerator = $paid > 0 ? $paid : $scheduledMonthly;

        return round($numerator / $income * 100, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function savingsSummary(User $user): array
    {
        $emergencyFund = $this->emergencyFundSection($user);
        $goals = $this->goalSection($user);
        $cashBuffer = $this->cashBufferSection($user);

        $available = $emergencyFund['available'] || $goals !== [] || $cashBuffer['available'];

        return [
            'available' => $available,
            ...($available ? [] : ['reason' => 'Flag an account as your emergency fund, add a savings goal, or let daily snapshots build up.']),
            'emergencyFund' => $emergencyFund,
            'goals' => $goals,
            'cashBuffer' => $cashBuffer,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emergencyFundSection(User $user): array
    {
        $accounts = $user->accounts()->where('is_emergency_fund', true)->get();

        if ($accounts->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'No account is flagged as your emergency fund yet.',
                'balance' => 0.0,
                'avgMonthlyExpenses' => 0.0,
                'monthsCovered' => null,
                'targetMonths' => (int) ($user->setting?->emergency_fund_target_months ?? 6),
                'progressPct' => null,
            ];
        }

        $balance = round((float) $accounts->sum('balance'), 2);

        $expenses = array_map(
            fn (Carbon $month) => $this->monthlyTotals($user, $month)['expenses'],
            $this->monthWindow(3),
        );

        $average = round(array_sum($expenses) / max(1, count($expenses)), 2);
        $targetMonths = (int) ($user->setting?->emergency_fund_target_months ?? 6);
        $monthsCovered = $average > 0 ? round($balance / $average, 2) : null;

        return [
            'available' => true,
            'balance' => $balance,
            'avgMonthlyExpenses' => $average,
            'monthsCovered' => $monthsCovered,
            'targetMonths' => $targetMonths,
            'progressPct' => $monthsCovered !== null && $targetMonths > 0
                ? round(min(100, $monthsCovered / $targetMonths * 100), 1)
                : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function goalSection(User $user): array
    {
        $today = Carbon::now()->startOfDay();

        return $user->goals()
            ->whereColumn('saved', '<', 'target')
            ->orderBy('position')
            ->get()
            ->map(function (Goal $goal) use ($today) {
                $remaining = round((float) $goal->target - (float) $goal->saved, 2);
                $perMonth = round((float) $goal->per_month, 2);

                $monthsRemaining = null;
                $requiredMonthly = null;

                if ($goal->by_date !== null) {
                    $days = max(0.0, (float) $today->diffInDays(Carbon::parse($goal->by_date)->startOfDay(), false));
                    $monthsRemaining = max(1, (int) ceil($days / 30.4375));
                    $requiredMonthly = round($remaining / $monthsRemaining, 2);
                }

                return [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'icon' => $goal->icon,
                    'color' => $goal->color,
                    'bg' => $goal->bg,
                    'saved' => round((float) $goal->saved, 2),
                    'target' => round((float) $goal->target, 2),
                    'progressPct' => (float) $goal->target > 0
                        ? round((float) $goal->saved / (float) $goal->target * 100, 1)
                        : 0.0,
                    'perMonth' => $perMonth,
                    'byDate' => $goal->by_date?->toDateString(),
                    'monthsRemaining' => $monthsRemaining,
                    'requiredMonthly' => $requiredMonthly,
                    'onTrack' => $requiredMonthly !== null ? $perMonth >= $requiredMonthly : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Lowest total cash balance reached in each of the last 12 months.
     *
     * @return array<string, mixed>
     */
    protected function cashBufferSection(User $user): array
    {
        $cashAccountIds = $user->accounts()->where('type', 'cash')->pluck('id');

        $windowStart = Carbon::now()->startOfMonth()->subMonths(11)->toDateString();

        $dailyTotals = AccountBalanceSnapshot::where('user_id', $user->id)
            ->whereIn('account_id', $cashAccountIds)
            ->where('snapshot_date', '>=', $windowStart)
            ->get(['balance', 'snapshot_date'])
            ->groupBy(fn (AccountBalanceSnapshot $snapshot) => $snapshot->snapshot_date->toDateString())
            ->map(fn ($group) => round((float) $group->sum('balance'), 2));

        $trend = [];

        foreach ($this->monthWindow(12) as $month) {
            $key = $month->format('Y-m');

            $inMonth = $dailyTotals->filter(fn ($value, $date) => str_starts_with($date, $key));

            if ($inMonth->isEmpty()) {
                continue;
            }

            $trend[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'value' => round((float) $inMonth->min(), 2),
            ];
        }

        if ($trend === []) {
            return [
                'available' => false,
                'reason' => 'No cash balance snapshots recorded yet.',
                'trend' => [],
            ];
        }

        return ['available' => true, 'trend' => $trend];
    }
}
