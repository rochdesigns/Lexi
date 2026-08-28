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
use App\Services\DebtFocus\LedgerService;
use App\Services\DebtFocus\PayoffEngine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
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

    public function storeBill(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->billRules());

        $request->user()->debtFocusBills()->create($validated);

        return back();
    }

    public function updateBill(Request $request, Bill $bill): RedirectResponse
    {
        abort_if($bill->user_id !== $request->user()->id, 403);

        $bill->update($request->validate($this->billRules()));

        return back();
    }

    public function destroyBill(Request $request, Bill $bill): RedirectResponse
    {
        abort_if($bill->user_id !== $request->user()->id, 403);

        $bill->delete();

        return back();
    }

    public function storeEnvelope(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->envelopeRules());

        $request->user()->debtFocusEnvelopes()->create($validated);

        return back();
    }

    public function updateEnvelope(Request $request, Envelope $envelope): RedirectResponse
    {
        abort_if($envelope->user_id !== $request->user()->id, 403);

        $envelope->update($request->validate($this->envelopeRules()));

        return back();
    }

    public function destroyEnvelope(Request $request, Envelope $envelope): RedirectResponse
    {
        abort_if($envelope->user_id !== $request->user()->id, 403);

        $envelope->delete();

        return back();
    }

    public function markBillPaid(Request $request, BillPeriod $billPeriod): RedirectResponse
    {
        abort_if($billPeriod->bill->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'actual_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        $billPeriod->update([
            'actual_amount' => $validated['actual_amount'],
            'paid_at' => now(),
        ]);

        return back();
    }

    public function confirmSweep(Request $request, EnvelopePeriod $envelopePeriod, BudgetService $budget, PayoffEngine $engine, LedgerService $ledger): RedirectResponse
    {
        abort_if($envelopePeriod->envelope->user_id !== $request->user()->id, 403);
        abort_if($envelopePeriod->sweep_accepted !== null, 422, 'This sweep has already been answered.');

        $user = $request->user();
        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', $this->assetAccountOwnershipRule($user->id)],
        ]);

        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $priorityDebt = $this->resolvePriorityLinkedDebt($this->liveDebts($user), $settings->strategy, $engine);

        abort_if($priorityDebt === null, 422, 'No linked debt available for this sweep.');

        $amount = round((float) $envelopePeriod->target - $budget->spentForPeriod($envelopePeriod), 2);
        abort_if($amount <= 0.0, 422, 'Nothing to sweep for this envelope.');

        $fromAccount = Account::findOrFail($validated['from_account_id']);
        $ledger->recordTransfer($fromAccount, $priorityDebt->linkedAccount, $amount, "Envelope sweep: {$envelopePeriod->envelope->name}");

        $envelopePeriod->update(['sweep_accepted' => true]);

        return back();
    }

    public function declineSweep(Request $request, EnvelopePeriod $envelopePeriod): RedirectResponse
    {
        abort_if($envelopePeriod->envelope->user_id !== $request->user()->id, 403);

        $envelopePeriod->update(['sweep_accepted' => false]);

        return back();
    }

    public function confirmExtraPayment(Request $request, BudgetService $budget, PayoffEngine $engine, LedgerService $ledger): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', $this->assetAccountOwnershipRule($user->id)],
            'year' => ['required', 'integer', 'min:2000'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $debts = $this->liveDebts($user);
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $priorityDebt = $this->resolvePriorityLinkedDebt($debts, $settings->strategy, $engine);

        abort_if($priorityDebt === null, 422, 'No linked debt available for the extra payment.');

        $alreadySent = Transaction::query()
            ->where('type', 'transfer')
            ->where('to_account_id', $priorityDebt->linked_account_id)
            ->where('description', 'Extra payment from leftover budget')
            ->whereBetween('occurred_at', $budget->periodBounds((int) $validated['year'], (int) $validated['month']))
            ->exists();

        abort_if($alreadySent, 422, 'An extra payment has already been sent this period.');

        $period = $budget->periodFor($user, (int) $validated['year'], (int) $validated['month']);
        $minimumsTotal = (float) $debts->sum(fn (Debt $debt) => (float) $debt->minimum_payment);
        $income = $this->incomeForPeriod($user, $budget, (int) $validated['year'], (int) $validated['month']);
        $leftover = $this->computeLeftover($income, $minimumsTotal, $period['bills'], $period['envelopes']);

        abort_if($leftover <= 0.0, 422, 'No leftover amount to send.');

        $fromAccount = Account::findOrFail($validated['from_account_id']);
        $ledger->recordTransfer(
            $fromAccount,
            $priorityDebt->linkedAccount,
            round($leftover, 2),
            'Extra payment from leftover budget',
            Carbon::create((int) $validated['year'], (int) $validated['month'], 1),
        );

        return back();
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

    /**
     * @return array<string, array<int, mixed>>
     */
    private function billRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'expected_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function envelopeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:50'],
            'default_target' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'active' => ['required', 'boolean'],
        ];
    }

    private function assetAccountOwnershipRule(int $userId): Exists
    {
        // Note: `->where('is_liability', false)` here would silently break — Laravel's
        // Exists rule builds its extra "wheres" as a comma-separated string, and PHP's
        // bool-to-string cast turns `false` into `""` (not "0"), so the generated
        // `exists:...,is_liability,""` clause never matches the stored 0/1 value. A
        // closure passes the raw boolean straight to the query builder instead.
        return Rule::exists('debt_focus_accounts', 'id')
            ->where('user_id', $userId)
            ->where(fn ($query) => $query->where('is_liability', false));
    }
}
