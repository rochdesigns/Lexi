<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class BudgetService
{
    /** How many past months a varying bill averages over. */
    private const VARYING_BILL_LOOKBACK_MONTHS = 3;

    /**
     * @return array{bills: Collection<int, BillPeriod>, envelopes: Collection<int, EnvelopePeriod>, sweepCandidates: Collection<int, EnvelopePeriod>}
     */
    public function periodFor(User $user, int $year, int $month): array
    {
        $isFirstViewOfMonth = ! $this->anyPeriodExists($user, $year, $month);

        $bills = $user->debtFocusBills()->where('active', true)->get()
            ->filter(fn (Bill $bill) => $bill->landsIn($year, $month))
            ->map(fn (Bill $bill) => BillPeriod::firstOrCreate(
                ['bill_id' => $bill->id, 'year' => $year, 'month' => $month],
                ['expected_amount' => $this->expectedAmountFor($bill, $year, $month)],
            ))
            ->values();

        $envelopes = $user->debtFocusEnvelopes()->where('active', true)->get()
            ->map(fn (Envelope $envelope) => EnvelopePeriod::firstOrCreate(
                ['envelope_id' => $envelope->id, 'year' => $year, 'month' => $month],
                ['target' => $envelope->default_target],
            ));

        if ($isFirstViewOfMonth) {
            $this->detectSweepCandidates($user, $year, $month);
        }

        $sweepCandidates = $this->pendingSweepCandidates($user, $year, $month);

        return ['bills' => $bills, 'envelopes' => $envelopes, 'sweepCandidates' => $sweepCandidates];
    }

    /**
     * What to set aside for a bill this month. A fixed bill uses its stated
     * amount; one marked as varying follows what it has actually cost lately,
     * so the envelope tracks reality instead of a figure that was a guess.
     */
    public function expectedAmountFor(Bill $bill, int $year, int $month): float
    {
        if (! $bill->amount_varies) {
            return (float) $bill->expected_amount;
        }

        $recent = $this->recentActuals($bill, $year, $month);

        return $recent === [] ? (float) $bill->expected_amount : round(array_sum($recent) / count($recent), 2);
    }

    /**
     * Brings already-materialised periods back in line with a bill that has just
     * been re-priced. A period is a snapshot taken the first time its month was
     * viewed, so without this an edit only shows up in months nobody has opened
     * yet. Paid periods and months already behind us are left as they were —
     * those record what was actually owed and settled, not what it costs now.
     */
    public function repriceUpcomingPeriods(Bill $bill, ?int $fromYear = null, ?int $fromMonth = null): void
    {
        $cutoff = ($fromYear ?? (int) now()->year) * 12 + ($fromMonth ?? (int) now()->month);

        BillPeriod::query()
            ->where('bill_id', $bill->id)
            ->whereNull('paid_at')
            ->get()
            ->filter(fn (BillPeriod $period) => ($period->year * 12 + $period->month) >= $cutoff)
            ->each(fn (BillPeriod $period) => $period->update([
                'expected_amount' => $this->expectedAmountFor($bill, $period->year, $period->month),
            ]));
    }

    /**
     * Brings already-materialised envelope periods back in line with an
     * envelope that has just been re-priced. A period is a snapshot taken the
     * first time its month was viewed, so without this an edit only shows up
     * in months nobody has opened yet. Months already behind us are left as
     * they were — those record what the envelope actually held at the time.
     */
    public function repriceUpcomingEnvelopePeriods(Envelope $envelope, ?int $fromYear = null, ?int $fromMonth = null): void
    {
        $cutoff = ($fromYear ?? (int) now()->year) * 12 + ($fromMonth ?? (int) now()->month);

        EnvelopePeriod::query()
            ->where('envelope_id', $envelope->id)
            ->get()
            ->filter(fn (EnvelopePeriod $period) => ($period->year * 12 + $period->month) >= $cutoff)
            ->each(fn (EnvelopePeriod $period) => $period->update(['target' => $envelope->default_target]));
    }

    /**
     * What the bill actually came to over the months leading up to this one,
     * most recent first, ignoring months it was never paid in.
     *
     * @return array<int, float>
     */
    private function recentActuals(Bill $bill, int $year, int $month): array
    {
        $cutoff = $year * 12 + $month;

        return BillPeriod::query()
            ->where('bill_id', $bill->id)
            ->whereNotNull('actual_amount')
            ->get()
            ->filter(fn (BillPeriod $period) => ($period->year * 12 + $period->month) < $cutoff)
            ->sortByDesc(fn (BillPeriod $period) => $period->year * 12 + $period->month)
            ->take(self::VARYING_BILL_LOOKBACK_MONTHS)
            ->map(fn (BillPeriod $period) => (float) $period->actual_amount)
            ->values()
            ->all();
    }

    /**
     * Income recorded in the period. This is the whole of what there is to place.
     */
    public function incomeForPeriod(User $user, int $year, int $month): float
    {
        [$start, $end] = $this->periodBounds($year, $month);

        return (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->whereDate('occurred_at', '>=', $start)
            ->whereDate('occurred_at', '<=', $end)
            ->sum('amount');
    }

    /**
     * Money that has arrived but has not been given a job yet — the "hand" the
     * Budget page asks you to empty. Everything already committed to a debt
     * minimum, a bill or an envelope is subtracted, so this only ever counts
     * money nothing is waiting on. Anything already sent at a debt beyond its
     * minimum comes out too, so sending the hand somewhere makes it fall.
     *
     * Goes negative when the month is over-committed — the plan claims more
     * than came in. That is worth seeing rather than flooring at zero, which
     * would show an empty hand and a settled month for a plan that does not
     * add up. Callers that spend from the hand already refuse anything at or
     * below zero, so the shortfall cannot be handed out by mistake.
     *
     * @param  Collection<int, BillPeriod>  $billPeriods
     * @param  Collection<int, EnvelopePeriod>  $envelopePeriods
     */
    public function unplaced(float $income, float $minimumsTotal, Collection $billPeriods, Collection $envelopePeriods, float $extraSentToDebts = 0.0): float
    {
        $billsTotal = (float) $billPeriods->sum(fn (BillPeriod $billPeriod) => (float) $billPeriod->expected_amount);
        $envelopesTotal = (float) $envelopePeriods->sum(fn (EnvelopePeriod $envelopePeriod) => (float) $envelopePeriod->target);

        return $income - $minimumsTotal - $billsTotal - $envelopesTotal - $extraSentToDebts;
    }

    /**
     * The unplaced money for a period, resolved from scratch. Convenience for
     * callers outside the Budget page that only need the one number.
     */
    public function unplacedFor(User $user, int $year, int $month, float $minimumsTotal, float $extraSentToDebts = 0.0): float
    {
        $period = $this->periodFor($user, $year, $month);

        return $this->unplaced(
            $this->incomeForPeriod($user, $year, $month),
            $minimumsTotal,
            $period['bills'],
            $period['envelopes'],
            $extraSentToDebts,
        );
    }

    /**
     * Whole days left in the period, counting today. Always at least one, so a
     * daily allowance computed from it never divides by zero on the last day.
     */
    public function daysRemainingIn(int $year, int $month): int
    {
        $end = Carbon::create($year, $month, 1)->endOfMonth();
        $today = Carbon::today();

        if ($today->greaterThan($end)) {
            return 1;
        }

        $from = $today->lessThan($end->copy()->startOfMonth()) ? $end->copy()->startOfMonth() : $today;

        return max(1, (int) $from->diffInDays($end) + 1);
    }

    /**
     * What is left unspent across the flexible envelopes, spread over the days
     * still to come. Overspent envelopes contribute nothing rather than a
     * negative, since their overspend is surfaced as its own decision.
     *
     * The plan is only ever as good as the money behind it, so the figure is
     * capped at the cash actually on hand. A null cash figure means no asset
     * account is recorded at all — there is nothing to vouch for the plan, so
     * the allowance reads as unknown rather than as a number to trust.
     *
     * @param  Collection<int, EnvelopePeriod>  $envelopePeriods
     * @return array{unspent: float, perDay: float, daysRemaining: int, planned: float, cashAvailable: float|null, cashKnown: bool, limitedByCash: bool}
     */
    public function safeToSpend(Collection $envelopePeriods, int $year, int $month, ?float $cashAvailable): array
    {
        $planned = (float) $envelopePeriods->sum(
            fn (EnvelopePeriod $period) => max(0.0, (float) $period->target - $this->spentForPeriod($period)),
        );

        $unspent = $cashAvailable === null ? 0.0 : min($planned, $cashAvailable);
        $daysRemaining = $this->daysRemainingIn($year, $month);

        return [
            'unspent' => round($unspent, 2),
            'perDay' => round($unspent / $daysRemaining, 2),
            'daysRemaining' => $daysRemaining,
            'planned' => round($planned, 2),
            'cashAvailable' => $cashAvailable === null ? null : round($cashAvailable, 2),
            'cashKnown' => $cashAvailable !== null,
            'limitedByCash' => $cashAvailable !== null && $cashAvailable < $planned,
        ];
    }

    /**
     * The money genuinely free to spend: what sits in the asset accounts once
     * this month's unpaid bills and debt minimums are set aside. Null when no
     * asset account exists, which is an unanswered question rather than a zero
     * balance and reads differently on the page.
     */
    public function cashAvailable(User $user, float $sealedTotal): ?float
    {
        $accounts = $user->debtFocusAccounts()->where('is_liability', false)->get();

        if ($accounts->isEmpty()) {
            return null;
        }

        return max(0.0, (float) $accounts->sum('balance') - $sealedTotal);
    }

    /**
     * Money this month has already claimed but not yet handed over — the bills
     * still to pay plus the part of each minimum still outstanding.
     *
     * @param  Collection<int, BillPeriod>  $billPeriods
     * @param  EloquentCollection<int, Debt>  $debts
     * @param  Collection<int, float>  $paidByDebt
     */
    public function sealedTotal(Collection $billPeriods, EloquentCollection $debts, Collection $paidByDebt): float
    {
        $unpaidBills = (float) $billPeriods
            ->filter(fn (BillPeriod $billPeriod) => $billPeriod->paid_at === null)
            ->sum(fn (BillPeriod $billPeriod) => (float) $billPeriod->expected_amount);

        $unpaidMinimums = (float) $debts->sum(
            fn (Debt $debt) => max(0.0, (float) $debt->minimum_payment - (float) ($paidByDebt[$debt->id] ?? 0)),
        );

        return $unpaidBills + $unpaidMinimums;
    }

    /**
     * The sealed money for a period, resolved from the rows that already exist.
     * Creates no period rows, so a caller outside the Budget page can ask
     * without starting a month off.
     */
    public function sealedTotalFor(User $user, int $year, int $month): float
    {
        $billPeriods = BillPeriod::query()
            ->with('bill')
            ->whereIn('bill_id', $user->debtFocusBills()->where('active', true)->select('id'))
            ->where('year', $year)
            ->where('month', $month)
            ->get();

        return $this->sealedTotal(
            $billPeriods,
            $this->liveDebts($user),
            $this->debtPaymentsForPeriod($user, $year, $month),
        );
    }

    /**
     * The debts still owed on, with any linked account's balance standing in
     * for the stored one so the figure follows the ledger.
     *
     * @return EloquentCollection<int, Debt>
     */
    public function liveDebts(User $user): EloquentCollection
    {
        $debts = $user->debtFocusDebts()->with('linkedAccount')->get();

        foreach ($debts as $debt) {
            if ($debt->linkedAccount !== null) {
                $debt->balance = $debt->linkedAccount->balance;
            }
        }

        return $debts->filter(fn (Debt $debt) => (float) $debt->balance > 0)->values();
    }

    /**
     * What has gone at each debt this period, keyed by debt id.
     *
     * @return Collection<int, float>
     */
    public function debtPaymentsForPeriod(User $user, int $year, int $month): Collection
    {
        [$start, $end] = $this->periodBounds($year, $month);

        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereNotNull('debt_id')
            ->whereDate('occurred_at', '>=', $start)
            ->whereDate('occurred_at', '<=', $end)
            ->selectRaw('debt_id, SUM(amount) as total')
            ->groupBy('debt_id')
            ->pluck('total', 'debt_id');
    }

    public function spentForPeriod(EnvelopePeriod $period): float
    {
        [$start, $end] = $this->periodBounds($period->year, $period->month);

        return (float) Transaction::query()
            ->where('envelope_id', $period->envelope_id)
            ->where('type', 'expense')
            ->whereDate('occurred_at', '>=', $start)
            ->whereDate('occurred_at', '<=', $end)
            ->sum('amount');
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function periodBounds(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    /**
     * Bills falling due within the next few days, for the Today page's money card.
     * Reads across the month boundary so a bill due early next month still shows,
     * and never creates period rows — opening Today must not start a month off.
     *
     * @return Collection<int, array{id: string, name: string, amount: float, dueLabel: string, daysUntil: int}>
     */
    public function upcomingBills(User $user, CarbonInterface $today, int $days = 7): Collection
    {
        $from = $today->copy()->startOfDay();
        $until = $from->copy()->addDays($days);

        $bills = $user->debtFocusBills()->where('active', true)->get();
        $upcoming = [];

        foreach ([$from, $from->copy()->addMonth()] as $inMonth) {
            $year = (int) $inMonth->year;
            $month = (int) $inMonth->month;

            foreach ($bills as $bill) {
                if ($bill->due_day === null || ! $bill->landsIn($year, $month)) {
                    continue;
                }

                $dueDate = $this->dueDateIn($bill, $year, $month);

                if (! $dueDate->betweenIncluded($from, $until) || $this->billIsPaidFor($bill, $year, $month)) {
                    continue;
                }

                $daysUntil = (int) $from->diffInDays($dueDate, false);

                $upcoming[] = [
                    'id' => "bill-{$bill->id}-{$year}-{$month}",
                    'name' => (string) $bill->name,
                    'amount' => $this->expectedAmountFor($bill, $year, $month),
                    'dueLabel' => match ($daysUntil) {
                        0 => 'Due today',
                        1 => 'Due tomorrow',
                        default => "Due in {$daysUntil} days",
                    },
                    'daysUntil' => $daysUntil,
                ];
            }
        }

        return collect($upcoming)->sortBy('daysUntil')->values();
    }

    /**
     * A bill's due day clamped to the length of the month, so a bill due on the
     * 31st still lands on the last day of a short month.
     */
    private function dueDateIn(Bill $bill, int $year, int $month): CarbonInterface
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return $start->copy()->setDay(min((int) $bill->due_day, (int) $start->copy()->endOfMonth()->day));
    }

    private function billIsPaidFor(Bill $bill, int $year, int $month): bool
    {
        return BillPeriod::where('bill_id', $bill->id)
            ->where('year', $year)
            ->where('month', $month)
            ->whereNotNull('paid_at')
            ->exists();
    }

    private function anyPeriodExists(User $user, int $year, int $month): bool
    {
        $billPeriodExists = BillPeriod::whereIn('bill_id', $user->debtFocusBills()->pluck('id'))
            ->where('year', $year)->where('month', $month)->exists();

        if ($billPeriodExists) {
            return true;
        }

        return EnvelopePeriod::whereIn('envelope_id', $user->debtFocusEnvelopes()->pluck('id'))
            ->where('year', $year)->where('month', $month)->exists();
    }

    /**
     * Stamps sweep_offered_at on any newly-underspent prior-month envelope period
     * that hasn't been offered yet. Does not return anything — pendingSweepCandidates()
     * is the single source of truth for what's currently shown to the user, whether
     * newly detected this call or still awaiting an answer from an earlier visit.
     */
    private function detectSweepCandidates(User $user, int $year, int $month): void
    {
        [$prevYear, $prevMonth] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];

        $candidates = EnvelopePeriod::whereIn('envelope_id', $user->debtFocusEnvelopes()->pluck('id'))
            ->where('year', $prevYear)->where('month', $prevMonth)
            ->whereNull('sweep_offered_at')
            ->get();

        $candidates->filter(fn (EnvelopePeriod $period) => (float) $period->target > $this->spentForPeriod($period))
            ->each(fn (EnvelopePeriod $period) => $period->update(['sweep_offered_at' => now()]));
    }

    /**
     * @return Collection<int, EnvelopePeriod>
     */
    private function pendingSweepCandidates(User $user, int $year, int $month): Collection
    {
        [$prevYear, $prevMonth] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];

        return EnvelopePeriod::whereIn('envelope_id', $user->debtFocusEnvelopes()->pluck('id'))
            ->where('year', $prevYear)->where('month', $prevMonth)
            ->whereNotNull('sweep_offered_at')
            ->whereNull('sweep_accepted')
            ->get();
    }
}
