<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

/**
 * Projects a cash runway: month by month, what comes in, what goes out, and
 * the balance that leaves behind. Answers the question neither /budget (one
 * month) nor /payoff-plan (debt, not cash) can — do the next twelve months
 * clear, and where does it get tight.
 *
 * Reads only. Unlike BudgetService::periodFor(), nothing here creates a
 * BillPeriod or EnvelopePeriod row, so looking a year ahead must never start
 * a year of months off.
 */
class ForecastService
{
    /** Months the runway looks ahead, counting the current one. */
    public const DEFAULT_HORIZON = 12;

    /** Past months averaged for the suggested income figure. */
    private const INCOME_SUGGESTION_LOOKBACK = 3;

    public function __construct(
        private readonly BudgetService $budget,
        private readonly PayoffEngine $engine,
    ) {}

    /**
     * @return array{
     *     months: array<int, array{year: int, month: int, income: float, bills: float,
     *                              envelopes: float, debt: float, net: float, balance: float|null}>,
     *     openingBalance: float|null,
     *     closingBalance: float|null,
     *     tightMonth: array{year: int, month: int, shortfall: float}|null,
     * }
     */
    public function project(User $user, float $expectedMonthlyIncome, int $horizon = self::DEFAULT_HORIZON): array
    {
        $today = Carbon::today();
        $balance = $this->openingBalance($user);
        $running = $balance;

        $debtPayments = $this->debtPaymentsByMonth($user, $horizon);
        $bills = $user->debtFocusBills()->where('active', true)->get();
        $envelopes = $user->debtFocusEnvelopes()->where('active', true)->get();

        $months = [];
        $tightMonth = null;

        for ($offset = 0; $offset < $horizon; $offset++) {
            $cursor = $today->copy()->startOfMonth()->addMonths($offset);
            $isCurrentMonth = $offset === 0;

            $row = [
                'year' => $cursor->year,
                'month' => $cursor->month,
                'income' => $isCurrentMonth
                    ? $this->incomeStillToCome($user, $cursor, $expectedMonthlyIncome)
                    : $expectedMonthlyIncome,
                'bills' => $isCurrentMonth
                    ? $this->billsStillToPay($user, $bills, $cursor)
                    : $this->billsFor($bills, $cursor),
                'envelopes' => $isCurrentMonth
                    ? $this->envelopeAllowanceLeft($user, $envelopes, $cursor)
                    : $this->envelopeTargetsFor($envelopes),
                'debt' => $isCurrentMonth
                    ? $this->debtStillToPay($user, $cursor, $debtPayments[$offset] ?? 0.0)
                    : ($debtPayments[$offset] ?? 0.0),
            ];

            $row['net'] = round($row['income'] - $row['bills'] - $row['envelopes'] - $row['debt'], 2);
            $running = $running === null ? null : round($running + $row['net'], 2);
            $row['balance'] = $running;

            $months[] = $row;

            if ($tightMonth === null && $this->isTight($row)) {
                $tightMonth = [
                    'year' => $row['year'],
                    'month' => $row['month'],
                    'shortfall' => abs($row['balance'] ?? $row['net']),
                ];
            }
        }

        return [
            'months' => $months,
            'openingBalance' => $balance,
            'closingBalance' => $running,
            'tightMonth' => $tightMonth,
        ];
    }

    /**
     * What the income field is prefilled with when it has never been answered:
     * what actually came in over the last few whole months. Null when there is
     * no history to average, which reads as an empty field rather than a zero.
     */
    public function suggestedMonthlyIncome(User $user): ?float
    {
        $cursor = Carbon::today()->startOfMonth();
        $totals = [];

        for ($back = 1; $back <= self::INCOME_SUGGESTION_LOOKBACK; $back++) {
            $month = $cursor->copy()->subMonths($back);
            $totals[] = $this->budget->incomeForPeriod($user, $month->year, $month->month);
        }

        $recorded = array_filter($totals, fn (float $total) => $total > 0.0);

        if ($recorded === []) {
            return null;
        }

        return round(array_sum($recorded) / count($recorded), 2);
    }

    /**
     * Cash on hand today. Null when the user has no asset account — an
     * unanswered question rather than a zero balance, the same distinction
     * BudgetService::cashAvailable() draws, and it suppresses the whole
     * running-balance column rather than inventing a starting point.
     */
    private function openingBalance(User $user): ?float
    {
        $accounts = $user->debtFocusAccounts()->where('is_liability', false)->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        return round((float) $accounts->sum('balance'), 2);
    }

    /**
     * A month is tight when it ends below zero. With no opening balance to
     * carry, the best available reading is a month that fails to cover itself.
     *
     * @param  array{net: float, balance: float|null}  $row
     */
    private function isTight(array $row): bool
    {
        return $row['balance'] !== null
            ? $row['balance'] < 0.0
            : $row['net'] < 0.0;
    }

    /**
     * Debt outflow per month, straight from the payoff engine. Deriving it here
     * from each debt's minimum would reimplement the engine's payment rules — a
     * final payment smaller than the minimum, a cleared debt's minimum rolling
     * onto the next target — in a second place, and the two would drift.
     *
     * @return array<int, float>
     */
    private function debtPaymentsByMonth(User $user, int $horizon): array
    {
        $settings = $user->debtFocusSettings;

        if ($settings === null) {
            return [];
        }

        $debts = $user->debtFocusDebts()->with('linkedAccount')->get();

        foreach ($debts as $debt) {
            if ($debt->linkedAccount !== null) {
                $debt->balance = $debt->linkedAccount->balance;
            }
        }

        if ($debts->isEmpty()) {
            return [];
        }

        $result = $this->engine->simulate(
            $debts,
            $settings->strategy,
            (float) $settings->extra_monthly,
            withTrajectory: true,
            priorityDebtId: $this->activePriorityDebtId($debts, $settings->priority_debt_id),
        );

        return array_slice($result['payments'] ?? [], 0, $horizon);
    }

    /**
     * A pin on an already-cleared debt is ignored, matching how the payoff plan
     * itself refuses to stall the queue on a debt with nothing left to pay.
     *
     * @param  Collection<int, Debt>  $debts
     */
    private function activePriorityDebtId($debts, ?int $priorityDebtId): ?int
    {
        if ($priorityDebtId === null) {
            return null;
        }

        $pinned = $debts->firstWhere('id', $priorityDebtId);

        return $pinned !== null && (float) $pinned->balance > 0.0 ? $priorityDebtId : null;
    }

    /**
     * Pay that has already landed sits in the opening balance, so only the
     * remainder of the month's expected income is still to come. Counting the
     * whole figure would add it twice.
     */
    private function incomeStillToCome(User $user, Carbon $cursor, float $expected): float
    {
        $received = $this->budget->incomeForPeriod($user, $cursor->year, $cursor->month);

        return round(max(0.0, $expected - $received), 2);
    }

    /**
     * @param  Collection<int, Bill>  $bills
     */
    private function billsFor($bills, Carbon $cursor): float
    {
        return round((float) $bills
            ->filter(fn (Bill $bill) => $bill->landsIn($cursor->year, $cursor->month))
            ->sum(fn (Bill $bill) => $this->budget->expectedAmountFor($bill, $cursor->year, $cursor->month)), 2);
    }

    /**
     * This month's bills that have not been paid yet. A bill already marked
     * paid has left the account the opening balance was read from. Where a
     * period row exists its amount wins, so an amount edited on /budget is the
     * one the forecast uses.
     *
     * @param  Collection<int, Bill>  $bills
     */
    private function billsStillToPay(User $user, $bills, Carbon $cursor): float
    {
        $periods = BillPeriod::query()
            ->whereIn('bill_id', $bills->pluck('id'))
            ->where('year', $cursor->year)
            ->where('month', $cursor->month)
            ->get()
            ->keyBy('bill_id');

        $total = 0.0;

        foreach ($bills as $bill) {
            if (! $bill->landsIn($cursor->year, $cursor->month)) {
                continue;
            }

            $period = $periods->get($bill->id);

            if ($period === null) {
                $total += $this->budget->expectedAmountFor($bill, $cursor->year, $cursor->month);

                continue;
            }

            if ($period->paid_at === null) {
                $total += (float) $period->expected_amount;
            }
        }

        return round($total, 2);
    }

    /**
     * @param  Collection<int, Envelope>  $envelopes
     */
    private function envelopeTargetsFor($envelopes): float
    {
        return round((float) $envelopes->sum(fn (Envelope $envelope) => (float) $envelope->default_target), 2);
    }

    /**
     * What is left of each envelope this month. Money already spent from one
     * has left the account, so only the unspent allowance is still ahead.
     *
     * @param  Collection<int, Envelope>  $envelopes
     */
    private function envelopeAllowanceLeft(User $user, $envelopes, Carbon $cursor): float
    {
        $periods = EnvelopePeriod::query()
            ->whereIn('envelope_id', $envelopes->pluck('id'))
            ->where('year', $cursor->year)
            ->where('month', $cursor->month)
            ->get()
            ->keyBy('envelope_id');

        $total = 0.0;

        foreach ($envelopes as $envelope) {
            $period = $periods->get($envelope->id);

            if ($period === null) {
                $total += (float) $envelope->default_target;

                continue;
            }

            $total += max(0.0, (float) $period->target - $this->budget->spentForPeriod($period));
        }

        return round($total, 2);
    }

    /**
     * Debt money already handed over this month is out of the account already,
     * so only the rest of the projected payment is still to come.
     */
    private function debtStillToPay(User $user, Carbon $cursor, float $projected): float
    {
        $paid = (float) $this->budget->debtPaymentsForPeriod($user, $cursor->year, $cursor->month)->sum();

        return round(max(0.0, $projected - $paid), 2);
    }
}
