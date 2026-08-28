<?php

namespace App\Http\Controllers;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use App\Services\DebtFocus\BudgetService;
use App\Services\DebtFocus\PayoffEngine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request, BudgetService $budget, PayoffEngine $engine): Response
    {
        $user = $request->user();
        $year = (int) $request->integer('year', (int) now()->year);
        $month = (int) $request->integer('month', (int) now()->month);

        $period = $budget->periodFor($user, $year, $month);

        $debts = $this->liveDebts($user);
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $priorityDebt = $this->resolvePriorityLinkedDebt($debts, $settings->strategy, $engine);

        $minimumsTotal = (float) $debts->sum(fn (Debt $debt) => (float) $debt->minimum_payment);
        $income = $this->incomeForPeriod($user, $budget, $year, $month);
        $leftover = $this->computeLeftover($income, $minimumsTotal, $period['bills'], $period['envelopes']);

        $assetAccounts = $user->debtFocusAccounts()->where('is_liability', false)->orderBy('name')->get()
            ->map(fn (Account $account) => ['id' => $account->id, 'name' => $account->name])
            ->values();

        return Inertia::render('Budget', [
            'year' => $year,
            'month' => $month,
            'monthLabel' => Carbon::create($year, $month, 1)->format('F Y'),
            'debtMinimums' => $debts->map(fn (Debt $debt) => [
                'id' => $debt->id,
                'name' => $debt->name,
                'minimum_payment' => (float) $debt->minimum_payment,
            ])->values(),
            'bills' => $period['bills']->map(fn (BillPeriod $billPeriod) => [
                'id' => $billPeriod->id,
                'bill_id' => $billPeriod->bill_id,
                'name' => $billPeriod->bill->name,
                'expected_amount' => (float) $billPeriod->expected_amount,
                'actual_amount' => $billPeriod->actual_amount !== null ? (float) $billPeriod->actual_amount : null,
                'due_day' => $billPeriod->bill->due_day,
                'paid_at' => $billPeriod->paid_at?->format('M j, Y'),
            ])->values(),
            'envelopes' => $period['envelopes']->map(fn (EnvelopePeriod $envelopePeriod) => [
                'id' => $envelopePeriod->id,
                'envelope_id' => $envelopePeriod->envelope_id,
                'name' => $envelopePeriod->envelope->name,
                'icon' => $envelopePeriod->envelope->icon,
                'target' => (float) $envelopePeriod->target,
                'spent' => round($budget->spentForPeriod($envelopePeriod), 2),
            ])->values(),
            'sweepCandidates' => $period['sweepCandidates']->map(fn (EnvelopePeriod $envelopePeriod) => [
                'id' => $envelopePeriod->id,
                'envelope_name' => $envelopePeriod->envelope->name,
                'freed_amount' => round((float) $envelopePeriod->target - $budget->spentForPeriod($envelopePeriod), 2),
            ])->values(),
            'leftover' => round($leftover, 2),
            'priorityDebt' => $priorityDebt !== null ? ['id' => $priorityDebt->id, 'name' => $priorityDebt->name] : null,
            'assetAccounts' => $assetAccounts,
            'billTemplates' => $user->debtFocusBills()->orderBy('name')->get()->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'name' => $bill->name,
                'expected_amount' => (float) $bill->expected_amount,
                'due_day' => $bill->due_day,
                'active' => $bill->active,
            ])->values(),
            'envelopeTemplates' => $user->debtFocusEnvelopes()->orderBy('name')->get()->map(fn (Envelope $envelope) => [
                'id' => $envelope->id,
                'name' => $envelope->name,
                'icon' => $envelope->icon,
                'default_target' => (float) $envelope->default_target,
                'active' => $envelope->active,
            ])->values(),
        ]);
    }

    /**
     * @return EloquentCollection<int, Debt>
     */
    private function liveDebts(User $user): EloquentCollection
    {
        $debts = $user->debtFocusDebts()->with('linkedAccount')->get();

        foreach ($debts as $debt) {
            if ($debt->linkedAccount !== null) {
                $debt->balance = $debt->linkedAccount->balance;
            }
        }

        return $debts;
    }

    /**
     * @param  Collection<int, Debt>  $debts
     */
    private function resolvePriorityLinkedDebt(Collection $debts, string $strategy, PayoffEngine $engine): ?Debt
    {
        foreach ($engine->orderByPriority($debts, $strategy) as $debt) {
            if ($debt->linked_account_id !== null) {
                return $debt;
            }
        }

        return null;
    }

    private function incomeForPeriod(User $user, BudgetService $budget, int $year, int $month): float
    {
        [$start, $end] = $budget->periodBounds($year, $month);

        return (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->whereBetween('occurred_at', [$start, $end])
            ->sum('amount');
    }

    /**
     * @param  Collection<int, BillPeriod>  $billPeriods
     * @param  Collection<int, EnvelopePeriod>  $envelopePeriods
     */
    private function computeLeftover(float $income, float $minimumsTotal, Collection $billPeriods, Collection $envelopePeriods): float
    {
        $billsTotal = (float) $billPeriods->sum(fn (BillPeriod $billPeriod) => (float) $billPeriod->expected_amount);
        $envelopesTotal = (float) $envelopePeriods->sum(fn (EnvelopePeriod $envelopePeriod) => (float) $envelopePeriod->target);

        return max(0.0, $income - $minimumsTotal - $billsTotal - $envelopesTotal);
    }
}
