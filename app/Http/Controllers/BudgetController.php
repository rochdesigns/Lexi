<?php

namespace App\Http\Controllers;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Settings;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    /** How much of the month must have passed before its spending rate says anything. */
    private const MIN_ELAPSED_TO_JUDGE_PACE = 0.1;

    /** Projected to finish under this share of its target, an envelope has spare to lend. */
    private const SPARE_PROJECTION_RATIO = 0.7;

    public function index(Request $request, BudgetService $budget, PayoffEngine $engine): Response
    {
        $user = $request->user();

        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $year = (int) ($validated['year'] ?? now()->year);
        $month = (int) ($validated['month'] ?? now()->month);

        $period = $budget->periodFor($user, $year, $month);

        $debts = $this->liveDebts($user);
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $priorityDebt = $this->resolvePriorityLinkedDebt($debts, $settings->strategy, $engine);

        $minimumsTotal = (float) $debts->sum(fn (Debt $debt) => (float) $debt->minimum_payment);
        $billsTotal = (float) $period['bills']->sum(fn (BillPeriod $billPeriod) => (float) $billPeriod->expected_amount);
        $envelopesTotal = (float) $period['envelopes']->sum(fn (EnvelopePeriod $envelopePeriod) => (float) $envelopePeriod->target);
        $income = $this->incomeForPeriod($user, $budget, $year, $month);
        $paidByDebt = $this->debtPaymentsForPeriod($user, $budget, $year, $month);
        $extraSent = $this->extraSentToDebts($debts, $paidByDebt);
        $leftover = $budget->unplaced($income, $minimumsTotal, $period['bills'], $period['envelopes'], $extraSent);

        $envelopeRows = $this->buildEnvelopeRows($period['envelopes'], $budget, $year, $month);
        $sealedTotal = $budget->sealedTotal($period['bills'], $debts, $paidByDebt);
        $safeToSpend = $budget->safeToSpend($period['envelopes'], $year, $month, $budget->cashAvailable($user, $sealedTotal));

        $accounts = $user->debtFocusAccounts()->orderBy('name')->get();

        // Two lists, because the page asks two different questions. Sending money
        // at a debt has to come from cash; settling a bill can just as well go on
        // a card, which moves the charge onto what is owed instead of out of cash.
        $assetAccounts = $accounts->where('is_liability', false)
            ->map(fn (Account $account) => ['id' => $account->id, 'name' => $account->name, 'balance' => (float) $account->balance])
            ->values();

        $billPaymentAccounts = $accounts
            ->map(fn (Account $account) => [
                'id' => $account->id,
                'name' => $account->name,
                'balance' => (float) $account->balance,
                'is_liability' => (bool) $account->is_liability,
            ])
            ->values();

        $isCurrentPeriod = $year === now()->year && $month === now()->month;

        return Inertia::render('Budget', [
            'year' => $year,
            'month' => $month,
            'monthLabel' => Carbon::create($year, $month, 1)->format('F Y'),
            'monthName' => Carbon::create($year, $month, 1)->format('F'),
            'debtMinimums' => $debts->sortBy(fn (Debt $debt) => $debt->due_day ?? PHP_INT_MAX)->values()->map(fn (Debt $debt) => [
                'id' => $debt->id,
                'name' => $debt->name,
                'minimum_payment' => (float) $debt->minimum_payment,
                'due_day' => $debt->due_day,
                'paid_this_period' => (float) ($paidByDebt[$debt->id] ?? 0),
            ])->values(),
            'bills' => $period['bills']->map(fn (BillPeriod $billPeriod) => [
                'id' => $billPeriod->id,
                'bill_id' => $billPeriod->bill_id,
                'name' => $billPeriod->bill->name,
                'expected_amount' => (float) $billPeriod->expected_amount,
                'actual_amount' => $billPeriod->actual_amount !== null ? (float) $billPeriod->actual_amount : null,
                'due_day' => $billPeriod->bill->due_day,
                'paid_from_account_id' => $billPeriod->bill->paid_from_account_id,
                'paid_at' => $billPeriod->paid_at?->format('M j, Y'),
            ])->values(),
            'envelopes' => $envelopeRows,
            'sweepCandidates' => $period['sweepCandidates']->map(fn (EnvelopePeriod $envelopePeriod) => [
                'id' => $envelopePeriod->id,
                'envelope_name' => $envelopePeriod->envelope->name,
                'freed_amount' => round((float) $envelopePeriod->target - $budget->spentForPeriod($envelopePeriod), 2),
            ])->values(),
            'leftover' => round($leftover, 2),
            'safeToSpend' => $safeToSpend,
            'sealedTotal' => round($sealedTotal, 2),
            'plannedDebtPayment' => $this->plannedDebtPayment($priorityDebt, $settings, $paidByDebt),
            'moveSuggestion' => $this->moveSuggestion($envelopeRows),
            'summary' => [
                'income' => round($income, 2),
                'debtMinimums' => round($minimumsTotal, 2),
                'bills' => round($billsTotal, 2),
                'envelopes' => round($envelopesTotal, 2),
                'leftover' => round($leftover, 2),
                'placed' => round(max(0.0, $income - $leftover), 2),
            ],
            'notifications' => $isCurrentPeriod
                ? $this->buildNotifications($debts, $paidByDebt, $period['bills'], $period['envelopes'], $budget)
                : [],
            'priorityDebt' => $priorityDebt !== null ? ['id' => $priorityDebt->id, 'name' => $priorityDebt->name] : null,
            'assetAccounts' => $assetAccounts,
            'billPaymentAccounts' => $billPaymentAccounts,
            'billTemplates' => $user->debtFocusBills()->orderBy('name')->get()->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'name' => $bill->name,
                'expected_amount' => (float) $bill->expected_amount,
                'frequency' => $bill->frequency,
                'anchor_month' => $bill->anchor_month,
                'amount_varies' => $bill->amount_varies,
                'paid_from_account_id' => $bill->paid_from_account_id,
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
        $validated = $request->validate($this->billRules($request->user()->id));

        $request->user()->debtFocusBills()->create($validated);

        return back();
    }

    public function updateBill(Request $request, Bill $bill, BudgetService $budget): RedirectResponse
    {
        abort_if($bill->user_id !== $request->user()->id, 403);

        $bill->update($request->validate($this->billRules($request->user()->id)));

        $budget->repriceUpcomingPeriods($bill);

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

    public function updateEnvelope(Request $request, Envelope $envelope, BudgetService $budget): RedirectResponse
    {
        abort_if($envelope->user_id !== $request->user()->id, 403);

        $envelope->update($request->validate($this->envelopeRules()));

        $budget->repriceUpcomingEnvelopePeriods($envelope);

        return back();
    }

    public function destroyEnvelope(Request $request, Envelope $envelope): RedirectResponse
    {
        abort_if($envelope->user_id !== $request->user()->id, 403);

        $envelope->delete();

        return back();
    }

    /**
     * Deals unplaced money across envelopes in one move — the hand emptied a
     * handful at a time. Each allocation raises that envelope's target for the
     * period; the total can never exceed what is actually in hand.
     */
    public function dealAcrossEnvelopes(Request $request, BudgetService $budget, PayoffEngine $engine): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.envelope_period_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];

        $requested = [];

        foreach ($validated['allocations'] as $allocation) {
            $requested[(int) $allocation['envelope_period_id']] = round((float) $allocation['amount'], 2);
        }

        $periods = $this->ownedEnvelopePeriods($user, array_keys($requested), $year, $month);

        $total = round(array_sum($requested), 2);
        $this->rejectIf($total <= 0.0, 'Nothing to place.');
        $this->rejectIf($total > $this->handFor($user, $budget, $engine, $year, $month) + 0.005, 'That is more than you have in hand.');

        foreach ($requested as $envelopePeriodId => $amount) {
            if ($amount <= 0.0) {
                continue;
            }

            $period = $periods[$envelopePeriodId];
            $period->update(['target' => round((float) $period->target + $amount, 2)]);
        }

        return back();
    }

    /**
     * Covers an overspent envelope from one of three honest sources: the hand,
     * another envelope's spare, or the extra that was going at the debt. The
     * money is already spent — this only decides where it came from. A source
     * with less than the full amount gives what it has rather than refusing.
     */
    public function coverOverspend(Request $request, EnvelopePeriod $envelopePeriod, BudgetService $budget, PayoffEngine $engine): RedirectResponse
    {
        $user = $request->user();
        abort_if($envelopePeriod->envelope->user_id !== $user->id, 403);

        $validated = $request->validate([
            'source' => ['required', 'string', 'in:hand,envelope,debt'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'from_envelope_period_id' => ['required_if:source,envelope', 'nullable', 'integer'],
        ]);

        $amount = round((float) $validated['amount'], 2);
        $year = (int) $envelopePeriod->year;
        $month = (int) $envelopePeriod->month;

        if ($validated['source'] === 'hand') {
            $available = round($this->handFor($user, $budget, $engine, $year, $month), 2);
            $this->rejectIf($available <= 0.005, 'There is nothing in hand to cover it with.');

            $amount = min($amount, $available);
        } elseif ($validated['source'] === 'envelope') {
            $sourceId = (int) $validated['from_envelope_period_id'];
            $source = $this->ownedEnvelopePeriods($user, [$sourceId], $year, $month)[$sourceId];
            $this->rejectIf($source->id === $envelopePeriod->id, 'An envelope cannot cover itself.');

            $spare = round((float) $source->target - $budget->spentForPeriod($source), 2);
            $this->rejectIf($spare <= 0.005, "{$source->envelope->name} has nothing spare to give.");

            $amount = min($amount, $spare);
            $source->update(['target' => round((float) $source->target - $amount, 2)]);
        } else {
            $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
            $extraMonthly = round((float) $settings->extra_monthly, 2);
            $this->rejectIf($extraMonthly <= 0.005, 'There is no extra debt payment to take from.');

            $amount = min($amount, $extraMonthly);
            $settings->update(['extra_monthly' => round($extraMonthly - $amount, 2)]);
        }

        $envelopePeriod->update(['target' => round((float) $envelopePeriod->target + $amount, 2)]);

        return back();
    }

    /**
     * Marks several bills paid in one pass, each with its own corrected amount,
     * so the sheet can tick a handful at once instead of one request per bill.
     */
    public function markBillsPaid(Request $request, LedgerService $ledger): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            // The account to fall back on for any bill that does not name one.
            'from_account_id' => ['required', 'integer', $this->payableAccountOwnershipRule($user->id)],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.bill_period_id' => ['required', 'integer'],
            'payments.*.actual_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            // Bills can each come out of a different account, so a payment may
            // override the fallback.
            'payments.*.from_account_id' => ['sometimes', 'nullable', 'integer', $this->payableAccountOwnershipRule($user->id)],
        ]);

        $requested = [];

        foreach ($validated['payments'] as $payment) {
            $requested[(int) $payment['bill_period_id']] = [
                'amount' => round((float) $payment['actual_amount'], 2),
                'from_account_id' => isset($payment['from_account_id'])
                    ? (int) $payment['from_account_id']
                    : (int) $validated['from_account_id'],
            ];
        }

        $billPeriods = BillPeriod::with('bill')->whereIn('id', array_keys($requested))->get()->keyBy('id');

        foreach (array_keys($requested) as $billPeriodId) {
            $billPeriod = $billPeriods->get($billPeriodId);

            abort_if($billPeriod === null || $billPeriod->bill->user_id !== $user->id, 403);
            $this->rejectIf($billPeriod->paid_at !== null, "{$billPeriod->bill->name} is already marked paid.");
        }

        $accounts = Account::query()
            ->whereIn('id', collect($requested)->pluck('from_account_id')->unique())
            ->get()
            ->keyBy('id');

        foreach ($requested as $billPeriodId => $payment) {
            $billPeriod = $billPeriods->get($billPeriodId);

            $transaction = $ledger->recordExpense(
                $accounts->get($payment['from_account_id']),
                $payment['amount'],
                "Bill: {$billPeriod->bill->name}",
            );

            $transaction->update(['bill_period_id' => $billPeriod->id]);
            $billPeriod->update(['actual_amount' => $payment['amount'], 'paid_at' => now()]);
        }

        return back();
    }

    public function markBillPaid(Request $request, BillPeriod $billPeriod, LedgerService $ledger): RedirectResponse
    {
        abort_if($billPeriod->bill->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'actual_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'from_account_id' => ['required', 'integer', $this->payableAccountOwnershipRule($request->user()->id)],
        ]);

        $fromAccount = Account::findOrFail((int) $validated['from_account_id']);
        $transaction = $ledger->recordExpense($fromAccount, (float) $validated['actual_amount'], "Bill: {$billPeriod->bill->name}");

        $transaction->update(['bill_period_id' => $billPeriod->id]);

        $billPeriod->update([
            'actual_amount' => $validated['actual_amount'],
            'paid_at' => now(),
        ]);

        return back();
    }

    /**
     * Puts a bill back to unpaid: the expense it wrote is reversed and removed,
     * and the period returns to waiting for its date. Marking a bill paid by
     * mistake is common enough that it needs a way back.
     */
    public function unmarkBillPaid(Request $request, BillPeriod $billPeriod, LedgerService $ledger): RedirectResponse
    {
        abort_if($billPeriod->bill->user_id !== $request->user()->id, 403);
        $this->rejectIf($billPeriod->paid_at === null, 'That bill is not marked paid.');

        DB::transaction(function () use ($billPeriod, $ledger) {
            // Bills paid before entries were linked have no transaction to undo;
            // the period still returns to unpaid, leaving the ledger untouched.
            foreach (Transaction::where('bill_period_id', $billPeriod->id)->get() as $transaction) {
                $ledger->reverseEffects($transaction);
                $transaction->delete();
            }

            $billPeriod->update(['actual_amount' => null, 'paid_at' => null]);
        });

        return back();
    }

    public function confirmSweep(Request $request, EnvelopePeriod $envelopePeriod, BudgetService $budget, PayoffEngine $engine, LedgerService $ledger): RedirectResponse
    {
        abort_if($envelopePeriod->envelope->user_id !== $request->user()->id, 403);
        $this->rejectIf($envelopePeriod->sweep_accepted !== null, 'This sweep has already been answered.');
        $this->rejectIf($envelopePeriod->sweep_offered_at === null, 'This envelope was never offered for a sweep.');

        $user = $request->user();
        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', $this->assetAccountOwnershipRule($user->id)],
        ]);

        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $priorityDebt = $this->resolvePriorityLinkedDebt($this->liveDebts($user), $settings->strategy, $engine);

        $this->rejectIf($priorityDebt === null, 'No linked debt available for this sweep.');

        $amount = round((float) $envelopePeriod->target - $budget->spentForPeriod($envelopePeriod), 2);
        $this->rejectIf($amount <= 0.0, 'Nothing to sweep for this envelope.');

        $fromAccount = Account::findOrFail((int) $validated['from_account_id']);
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
            'amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        $debts = $this->liveDebts($user);
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $priorityDebt = $this->resolvePriorityLinkedDebt($debts, $settings->strategy, $engine);

        $this->rejectIf($priorityDebt === null, 'No linked debt available for the extra payment.');

        $alreadySent = Transaction::query()
            ->where('type', 'transfer')
            ->where('to_account_id', $priorityDebt->linked_account_id)
            ->where('description', 'Extra payment from leftover budget')
            ->whereBetween('occurred_at', $budget->periodBounds((int) $validated['year'], (int) $validated['month']))
            ->exists();

        $this->rejectIf($alreadySent, 'An extra payment has already been sent this period.');

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];

        $period = $budget->periodFor($user, $year, $month);
        $minimumsTotal = (float) $debts->sum(fn (Debt $debt) => (float) $debt->minimum_payment);
        $income = $this->incomeForPeriod($user, $budget, $year, $month);
        $paidByDebt = $this->debtPaymentsForPeriod($user, $budget, $year, $month);
        $leftover = $budget->unplaced($income, $minimumsTotal, $period['bills'], $period['envelopes'], $this->extraSentToDebts($debts, $paidByDebt));

        $this->rejectIf($leftover <= 0.0, 'No leftover amount to send.');

        $amount = isset($validated['amount']) ? round((float) $validated['amount'], 2) : round($leftover, 2);
        $this->rejectIf($amount > $leftover + 0.005, 'That is more than you have in hand.');

        $fromAccount = Account::findOrFail((int) $validated['from_account_id']);
        $transaction = $ledger->recordTransfer(
            $fromAccount,
            $priorityDebt->linkedAccount,
            $amount,
            'Extra payment from leftover budget',
            Carbon::create($year, $month, 1),
        );

        $transaction->update(['debt_id' => $priorityDebt->id]);

        return back();
    }

    public function payDebtMinimum(Request $request, Debt $debt, LedgerService $ledger): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', $this->assetAccountOwnershipRule($request->user()->id)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'occurred_at' => ['required', 'date'],
        ]);

        $fromAccount = Account::findOrFail((int) $validated['from_account_id']);
        $amount = round((float) $validated['amount'], 2);
        $occurredAt = Carbon::parse($validated['occurred_at']);
        $description = "Debt minimum: {$debt->name}";

        if ($debt->linked_account_id !== null) {
            $transaction = $ledger->recordTransfer($fromAccount, $debt->linkedAccount, $amount, $description, $occurredAt);
        } else {
            $transaction = $ledger->recordExpense($fromAccount, $amount, $description, $occurredAt);
            $debt->update(['balance' => max(0.0, (float) $debt->balance - $amount)]);
        }

        $transaction->update(['debt_id' => $debt->id]);

        return back();
    }

    /**
     * @return EloquentCollection<int, Debt>
     */
    private function liveDebts(User $user): EloquentCollection
    {
        return app(BudgetService::class)->liveDebts($user);
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
            ->whereDate('occurred_at', '>=', $start)
            ->whereDate('occurred_at', '<=', $end)
            ->sum('amount');
    }

    /**
     * @return Collection<int, float>
     */
    private function debtPaymentsForPeriod(User $user, BudgetService $budget, int $year, int $month): Collection
    {
        return $budget->debtPaymentsForPeriod($user, $year, $month);
    }

    /**
     * @param  EloquentCollection<int, Debt>  $debts
     * @param  Collection<int, float>  $paidByDebt
     * @param  Collection<int, BillPeriod>  $billPeriods
     * @param  Collection<int, EnvelopePeriod>  $envelopePeriods
     * @return array<int, array{type: string, severity: string, subject: string, days?: int, amount?: float}>
     */
    private function buildNotifications(EloquentCollection $debts, Collection $paidByDebt, Collection $billPeriods, Collection $envelopePeriods, BudgetService $budget): array
    {
        $today = now()->day;
        $notifications = [];

        foreach ($billPeriods as $billPeriod) {
            if ($billPeriod->paid_at !== null || $billPeriod->bill->due_day === null) {
                continue;
            }

            $daysUntilDue = $billPeriod->bill->due_day - $today;

            if ($daysUntilDue < 0) {
                $notifications[] = ['type' => 'bill_overdue', 'severity' => 'critical', 'subject' => $billPeriod->bill->name, 'days' => abs($daysUntilDue)];
            } elseif ($daysUntilDue <= 3) {
                $notifications[] = ['type' => 'bill_due_soon', 'severity' => 'warning', 'subject' => $billPeriod->bill->name, 'days' => $daysUntilDue];
            }
        }

        foreach ($envelopePeriods as $envelopePeriod) {
            $spent = $budget->spentForPeriod($envelopePeriod);
            $target = (float) $envelopePeriod->target;

            if ($spent > $target) {
                $notifications[] = ['type' => 'envelope_over_budget', 'severity' => 'warning', 'subject' => $envelopePeriod->envelope->name, 'amount' => round($spent - $target, 2)];
            }
        }

        foreach ($debts as $debt) {
            if ($debt->due_day === null) {
                continue;
            }

            $paid = (float) ($paidByDebt[$debt->id] ?? 0);

            if ($paid >= (float) $debt->minimum_payment) {
                continue;
            }

            if ($debt->due_day < $debt->created_at->day && $debt->created_at->isSameMonth(now())) {
                continue;
            }

            $daysUntilDue = $debt->due_day - $today;

            if ($daysUntilDue < 0) {
                $notifications[] = ['type' => 'debt_minimum_overdue', 'severity' => 'critical', 'subject' => $debt->name, 'days' => abs($daysUntilDue)];
            } elseif ($daysUntilDue <= 3) {
                $notifications[] = ['type' => 'debt_minimum_due_soon', 'severity' => 'warning', 'subject' => $debt->name, 'days' => $daysUntilDue];
            }
        }

        usort($notifications, fn (array $a, array $b) => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));

        return $notifications;
    }

    /**
     * Envelopes shaped for the page: what is left rather than what was spent,
     * plus whether that remainder is keeping up with the calendar. The card
     * fills from the bottom with what survives, so `remaining` is the headline.
     *
     * @param  Collection<int, EnvelopePeriod>  $envelopePeriods
     * @return array<int, array{id: int, envelope_id: int, name: string, icon: string|null, target: float, spent: float, remaining: float, status: string}>
     */
    private function buildEnvelopeRows(Collection $envelopePeriods, BudgetService $budget, int $year, int $month): array
    {
        $daysInMonth = (int) Carbon::create($year, $month, 1)->daysInMonth;
        $daysRemaining = $budget->daysRemainingIn($year, $month);
        $elapsed = $daysInMonth > 0 ? 1.0 - ($daysRemaining / $daysInMonth) : 1.0;

        return $envelopePeriods->map(function (EnvelopePeriod $envelopePeriod) use ($budget, $elapsed) {
            $target = (float) $envelopePeriod->target;
            $spent = round($budget->spentForPeriod($envelopePeriod), 2);
            $remaining = round($target - $spent, 2);

            return [
                'id' => $envelopePeriod->id,
                'envelope_id' => $envelopePeriod->envelope_id,
                'name' => $envelopePeriod->envelope->name,
                'icon' => $envelopePeriod->envelope->icon,
                'target' => $target,
                'spent' => $spent,
                'remaining' => $remaining,
                'status' => $this->envelopeStatus($target, $spent, $remaining, $elapsed),
            ];
        })->values()->all();
    }

    /**
     * Judged on the rate it is actually being spent, not on the calendar alone:
     * carry this month's pace to the end of it and see whether the envelope runs
     * out or finishes with money left. Too early in the month to extrapolate
     * from, nothing is called either way.
     */
    private function envelopeStatus(float $target, float $spent, float $remaining, float $elapsed): string
    {
        if ($remaining < 0.0) {
            return 'over';
        }

        if ($target <= 0.0 || $elapsed < self::MIN_ELAPSED_TO_JUDGE_PACE) {
            return 'on_pace';
        }

        $projectedSpend = $spent / $elapsed;

        if ($projectedSpend > $target) {
            return 'tight';
        }

        return $projectedSpend < $target * self::SPARE_PROJECTION_RATIO ? 'spare' : 'on_pace';
    }

    /**
     * Money already promised to a date this month: bills still to be paid, plus
     * whatever is left of each debt minimum.
     *
     * @param  Collection<int, BillPeriod>  $billPeriods
     * @param  EloquentCollection<int, Debt>  $debts
     * @param  Collection<int, float>  $paidByDebt
     */
    /**
     * What is going at the priority debt this month and when — the minimum plus
     * whatever standing extra is set, less anything already sent.
     *
     * @param  Collection<int, float>  $paidByDebt
     * @return array{id: int, name: string, amount: float, minimum: float, due_day: int|null, already_paid: float}|null
     */
    private function plannedDebtPayment(?Debt $priorityDebt, Settings $settings, Collection $paidByDebt): ?array
    {
        if ($priorityDebt === null) {
            return null;
        }

        $planned = (float) $priorityDebt->minimum_payment + (float) $settings->extra_monthly;

        return [
            'id' => $priorityDebt->id,
            'name' => $priorityDebt->name,
            'amount' => round($planned, 2),
            'minimum' => round((float) $priorityDebt->minimum_payment, 2),
            'due_day' => $priorityDebt->due_day,
            'already_paid' => round((float) ($paidByDebt[$priorityDebt->id] ?? 0), 2),
        ];
    }

    /**
     * Pairs the envelope with the most spare against the one running tightest,
     * so the page can offer the move rather than making the user find it.
     *
     * @param  array<int, array{id: int, name: string, remaining: float, status: string}>  $envelopeRows
     * @return array{from_id: int, from_name: string, spare: float, to_id: int, to_name: string}|null
     */
    private function moveSuggestion(array $envelopeRows): ?array
    {
        $rows = collect($envelopeRows);

        $spare = $rows->where('status', 'spare')->sortByDesc('remaining')->first();
        $needy = $rows->whereIn('status', ['over', 'tight'])->sortBy('remaining')->first();

        if ($spare === null || $needy === null || $spare['id'] === $needy['id'] || $spare['remaining'] <= 0.0) {
            return null;
        }

        return [
            'from_id' => $spare['id'],
            'from_name' => $spare['name'],
            'spare' => round((float) $spare['remaining'], 2),
            'to_id' => $needy['id'],
            'to_name' => $needy['name'],
        ];
    }

    /**
     * Everything sent at debts this period beyond their minimums. This is what
     * makes the hand fall when money is sent at a debt rather than an envelope.
     *
     * @param  EloquentCollection<int, Debt>  $debts
     * @param  Collection<int, float>  $paidByDebt
     */
    private function extraSentToDebts(EloquentCollection $debts, Collection $paidByDebt): float
    {
        return (float) $debts->sum(
            fn (Debt $debt) => max(0.0, (float) ($paidByDebt[$debt->id] ?? 0) - (float) $debt->minimum_payment),
        );
    }

    /**
     * The unplaced money for a period, resolved the same way the page resolves it.
     */
    private function handFor(User $user, BudgetService $budget, PayoffEngine $engine, int $year, int $month): float
    {
        $debts = $this->liveDebts($user);
        $minimumsTotal = (float) $debts->sum(fn (Debt $debt) => (float) $debt->minimum_payment);
        $paidByDebt = $this->debtPaymentsForPeriod($user, $budget, $year, $month);

        return $budget->unplacedFor($user, $year, $month, $minimumsTotal, $this->extraSentToDebts($debts, $paidByDebt));
    }

    /**
     * Loads envelope periods for the given ids, refusing anything that is not
     * this user's or is not in the period being edited.
     *
     * @param  array<int, int|null>  $ids
     * @return Collection<int, EnvelopePeriod>
     */
    private function ownedEnvelopePeriods(User $user, array $ids, int $year, int $month): Collection
    {
        $periods = EnvelopePeriod::with('envelope')
            ->whereIn('id', array_filter($ids))
            ->get()
            ->keyBy('id');

        foreach (array_filter($ids) as $id) {
            $period = $periods->get($id);

            abort_if($period === null || $period->envelope->user_id !== $user->id, 403);
            $this->rejectIf($period->year !== $year || $period->month !== $month, 'That envelope belongs to another month.');
        }

        return $periods;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function billRules(int $userId): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'expected_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            // Optional: a caller that says nothing about recurrence gets a
            // plain monthly bill at a fixed amount, which is what the columns
            // already default to.
            'frequency' => ['sometimes', 'string', Rule::in(Bill::FREQUENCIES)],
            // Only means anything less often than monthly; the model falls back
            // to the month the bill was created in when it is left out.
            'anchor_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'amount_varies' => ['sometimes', 'boolean'],
            // Where this bill is normally paid from, so the mark-paid sheet
            // does not have to ask again every month.
            'paid_from_account_id' => ['sometimes', 'nullable', 'integer', $this->payableAccountOwnershipRule($userId)],
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

    /**
     * Anywhere a bill can actually be settled from: cash accounts and credit
     * cards alike. Charging a bill to a card is a real way to pay it, so this
     * deliberately does not filter on is_liability the way the cash-only rule
     * below does. Paying down a debt is the other story — that still has to
     * come out of cash, or it is a balance transfer wearing a payment's hat.
     */
    private function payableAccountOwnershipRule(int $userId): Exists
    {
        return Rule::exists('debt_focus_accounts', 'id')->where('user_id', $userId);
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
