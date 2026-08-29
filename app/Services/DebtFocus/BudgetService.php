<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BudgetService
{
    /**
     * @return array{bills: Collection<int, BillPeriod>, envelopes: Collection<int, EnvelopePeriod>, sweepCandidates: Collection<int, EnvelopePeriod>}
     */
    public function periodFor(User $user, int $year, int $month): array
    {
        $isFirstViewOfMonth = ! $this->anyPeriodExists($user, $year, $month);

        $bills = $user->debtFocusBills()->where('active', true)->get()
            ->map(fn (Bill $bill) => BillPeriod::firstOrCreate(
                ['bill_id' => $bill->id, 'year' => $year, 'month' => $month],
                ['expected_amount' => $bill->expected_amount],
            ));

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
