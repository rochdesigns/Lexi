<?php

namespace App\Services;

use App\Models\AccountBalanceSnapshot;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Net worth trend and debt payoff simulation, used by MoneyController.
 *
 * Each public method takes its inputs explicitly — the service holds no per-user state.
 * `netWorth()` returns ['available' => false, 'reason' => '…'] rather than zeros that would
 * read as a real (and alarming) result when the underlying snapshot data is missing.
 * `simulatePayoff()` and `payoffInputs()` have no such data-availability gate.
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

        $accountByMonth = $this->lastBalanceDetailPerMonth(
            AccountBalanceSnapshot::where('user_id', $user->id)
                ->whereNotIn('account_id', $linkedCreditIds)
                ->where('snapshot_date', '>=', $windowStart->toDateString())
                ->orderBy('snapshot_date')
                ->get(),
            'account_id',
        );

        $debtByMonth = $this->lastBalanceDetailPerMonth(
            DebtBalanceSnapshot::where('user_id', $user->id)
                ->where('snapshot_date', '>=', $windowStart->toDateString())
                ->orderBy('snapshot_date')
                ->get(),
            'debt_id',
        );

        $expectedAccountIds = $user->accounts()->whereNotIn('id', $linkedCreditIds)->pluck('id')->all();
        $expectedDebtIds = $user->debts()->pluck('id')->all();
        $hasDebts = $expectedDebtIds !== [];

        $trend = [];

        foreach ($this->monthWindow(12) as $month) {
            $key = $month->format('Y-m');

            // A partially snapshotted month understates the total: the accounts or
            // debts that did not report would silently read as ₱0. Both sides must be
            // fully covered before a month can join the trend.
            if (! array_key_exists($key, $accountByMonth)
                || ! $this->coversEveryOwner($accountByMonth[$key]['owners'], $expectedAccountIds)) {
                continue;
            }

            // A user with debts but no debt snapshot for this month has unknown
            // (not zero) debt for that month. Only a user with no debts at all can
            // safely treat missing as zero.
            if ($hasDebts
                && (! array_key_exists($key, $debtByMonth)
                    || ! $this->coversEveryOwner($debtByMonth[$key]['owners'], $expectedDebtIds))) {
                continue;
            }

            $trend[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'value' => round($accountByMonth[$key]['total'] - ($debtByMonth[$key]['total'] ?? 0.0), 2),
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
     * Sum of each owner's latest snapshot within each calendar month, alongside the
     * owners that actually reported one — a total is only trustworthy when every
     * account (or debt) contributed to it.
     *
     * @param  Collection<int, AccountBalanceSnapshot|DebtBalanceSnapshot>  $snapshots
     * @param  'account_id'|'debt_id'  $ownerKey
     * @return array<string, array{total: float, owners: list<int>}>
     */
    private function lastBalanceDetailPerMonth(Collection $snapshots, string $ownerKey): array
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

        $detail = [];

        foreach ($latest as $month => $owners) {
            $detail[$month] = [
                'total' => round(array_sum(array_column($owners, 'balance')), 2),
                'owners' => array_map('intval', array_keys($owners)),
            ];
        }

        return $detail;
    }

    /**
     * Whether every expected owner reported a snapshot for a month.
     *
     * Simplification: `$expected` is the user's CURRENT set of accounts (or debts)
     * rather than the set that existed as of that month, which is not recoverable
     * from the schema. An account opened today therefore invalidates earlier months
     * instead of letting them read as a smaller net worth — the conservative
     * direction, and consistent with this report never showing misleading zeros.
     *
     * @param  list<int>  $reported
     * @param  list<int>  $expected
     */
    private function coversEveryOwner(array $reported, array $expected): bool
    {
        return array_diff($expected, $reported) === [];
    }

    /**
     * Generalized amortization simulation, used by MoneyController's payoff projection.
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
     * Builds simulatePayoff()'s input shape from a debt collection, in the collection's
     * current order — callers that care about avalanche ordering should sort before
     * calling this.
     *
     * @param  Collection<int, Debt>  $debts
     * @return list<array{balance: float, perMonth: float, monthlyRate: float}>
     */
    public function payoffInputs(Collection $debts): array
    {
        return $debts->map(fn (Debt $debt) => [
            'balance' => (float) $debt->balance,
            'perMonth' => (float) $debt->per_month,
            'monthlyRate' => $this->monthlyRate($debt),
        ])->values()->all();
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
}
