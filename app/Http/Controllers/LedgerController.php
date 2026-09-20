<?php

namespace App\Http\Controllers;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use App\Services\DebtFocus\LedgerReportService;
use App\Services\DebtFocus\LedgerService;
use App\Services\DebtFocus\PayoffEngine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class LedgerController extends Controller
{
    /**
     * How many entries the activity feed loads for the client to filter over.
     */
    private const ACTIVITY_LIMIT = 500;

    public function index(Request $request, LedgerReportService $report, PayoffEngine $engine): Response
    {
        $user = $request->user();

        $accounts = $user->debtFocusAccounts()->orderBy('name')->get();
        $linkedDebtNames = Debt::query()
            ->whereIn('linked_account_id', $accounts->pluck('id'))
            ->get(['name', 'linked_account_id'])
            ->groupBy('linked_account_id');

        $accountProps = $accounts->map(fn (Account $account) => [
            'id' => $account->id,
            'name' => $account->name,
            'institution' => $account->institution,
            'type' => $account->type,
            'is_liability' => $account->is_liability,
            'balance' => (float) $account->balance,
            'linked_debt_names' => $linkedDebtNames->get($account->id)?->pluck('name')->all() ?? [],
        ])->values();

        $selected = $this->resolveSelectedAccount($request, $accounts);
        [$range, $from, $to] = $this->resolveRange($request);
        $history = $report->netWorthHistory($user);

        $totalAssets = (float) $accounts->where('is_liability', false)->sum('balance');
        $totalLiabilities = (float) $accounts->where('is_liability', true)->sum('balance');

        return Inertia::render('Ledger', [
            'accounts' => $accountProps,
            'selectedAccountId' => $selected?->id,
            'envelopes' => $user->debtFocusEnvelopes()->where('active', true)->orderBy('name')->get()
                ->map(fn (Envelope $envelope) => ['id' => $envelope->id, 'name' => $envelope->name, 'icon' => $envelope->icon])
                ->values(),
            'totals' => [
                'assets' => round($totalAssets, 2),
                'liabilities' => round($totalLiabilities, 2),
                'netWorth' => round($totalAssets - $totalLiabilities, 2),
                'changeThisMonth' => $this->changeThisMonth($history),
            ],
            'debts' => $this->debtProps($user, $engine),
            'netWorthHistory' => $history,
            'flow' => $selected !== null ? $report->monthlyFlow($selected, Carbon::now()) : null,
            'totalFlow' => $accounts->isEmpty() ? null : $report->monthlyFlowTotal($user, Carbon::now()),
            'activity' => [
                'entries' => $selected !== null ? $report->activityFor($selected, $from, $to, self::ACTIVITY_LIMIT) : [],
                'range_label' => $this->rangeLabel($range, $from, $to),
            ],
            'filters' => [
                'range' => $range,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->accountRules());

        $request->user()->debtFocusAccounts()->create($validated);

        return back();
    }

    /**
     * A balance typed here is a correction, not a transfer or a spend — the
     * difference is landed as an ordinary transaction so it takes its place in
     * the ledger's history and flow totals instead of silently overwriting them.
     */
    public function updateAccount(Request $request, Account $account, LedgerService $ledger): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $validated = $request->validate($this->accountRules());
        $oldBalance = round((float) $account->balance, 2);
        $newBalance = round((float) $validated['balance'], 2);
        unset($validated['balance']);

        $account->update($validated);

        $delta = round($newBalance - $oldBalance, 2);

        if (abs($delta) > 0.005) {
            $isGain = $account->is_liability ? $delta < 0 : $delta > 0;

            $isGain
                ? $ledger->recordIncome($account, abs($delta), 'Balance correction')
                : $ledger->recordExpense($account, abs($delta), 'Balance correction');
        }

        return back();
    }

    public function destroyAccount(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        DB::transaction(function () use ($account) {
            Debt::where('linked_account_id', $account->id)->delete();
            $account->delete();

            Transaction::where('user_id', $account->user_id)
                ->where('type', 'transfer')
                ->whereNull('from_account_id')
                ->whereNull('to_account_id')
                ->delete();
        });

        return back();
    }

    public function storeTransaction(Request $request, LedgerService $ledger): RedirectResponse
    {
        $ownedAccount = $this->accountOwnershipRule($request->user()->id);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:income,expense,transfer,debt'],
            'account_id' => ['required_unless:type,transfer,debt', 'nullable', 'integer', $ownedAccount],
            'from_account_id' => ['required_if:type,transfer,debt', 'nullable', 'integer', 'different:to_account_id', $ownedAccount],
            'to_account_id' => ['required_if:type,transfer', 'nullable', 'integer', $ownedAccount],
            'debt_id' => ['required_if:type,debt', 'nullable', 'integer', 'prohibited_unless:type,debt', $this->debtOwnershipRule($request->user()->id)],
            'envelope_id' => ['nullable', 'integer', 'prohibited_unless:type,expense', $this->envelopeOwnershipRule($request->user()->id)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['required', 'date_format:Y-m-d'],
        ]);

        $amount = (float) $validated['amount'];
        $description = $validated['description'] ?? null;
        $occurredAt = Carbon::parse($validated['occurred_at']);

        if ($validated['type'] === 'debt') {
            $this->recordDebtPayment(
                Debt::findOrFail((int) $validated['debt_id']),
                Account::findOrFail((int) $validated['from_account_id']),
                $amount,
                $description,
                $occurredAt,
                $ledger,
            );

            return back();
        }

        match ($validated['type']) {
            'income' => $ledger->recordIncome(Account::findOrFail((int) $validated['account_id']), $amount, $description, $occurredAt),
            'expense' => $ledger->recordExpense(Account::findOrFail((int) $validated['account_id']), $amount, $description, $occurredAt, $validated['envelope_id'] ?? null),
            'transfer' => $ledger->recordTransfer(Account::findOrFail((int) $validated['from_account_id']), Account::findOrFail((int) $validated['to_account_id']), $amount, $description, $occurredAt),
            // Validation constrains `type` to the arms above; this keeps the
            // match total so a new entry type cannot slip through unhandled.
            default => throw new InvalidArgumentException("Unsupported entry type: {$validated['type']}"),
        };

        return back();
    }

    /**
     * Edits an entry in place. The old effects are reversed and the new ones
     * applied, so every balance the entry ever touched ends up where it should
     * be rather than drifting by the difference.
     *
     * The entry's type is fixed: turning an expense into a transfer is a
     * different entry, made by deleting this one and adding that.
     */
    public function updateTransaction(Request $request, Transaction $transaction, LedgerService $ledger): RedirectResponse
    {
        $user = $request->user();
        abort_if($transaction->user_id !== $user->id, 403);

        $ownedAccount = $this->accountOwnershipRule($user->id);

        $validated = $request->validate([
            'account_id' => [Rule::requiredIf($transaction->type !== 'transfer'), 'nullable', 'integer', $ownedAccount],
            'from_account_id' => [Rule::requiredIf($transaction->type === 'transfer'), 'nullable', 'integer', 'different:to_account_id', $ownedAccount],
            'to_account_id' => [Rule::requiredIf($transaction->type === 'transfer'), 'nullable', 'integer', $ownedAccount],
            'envelope_id' => ['nullable', 'integer', $this->envelopeOwnershipRule($user->id)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['required', 'date_format:Y-m-d'],
        ]);

        DB::transaction(function () use ($transaction, $validated, $ledger) {
            $ledger->reverseEffects($transaction);

            $attributes = [
                'amount' => round((float) $validated['amount'], 2),
                'description' => $validated['description'] ?? null,
                'occurred_at' => Carbon::parse($validated['occurred_at']),
                // Only an expense sits in an envelope; anything else keeps none.
                'envelope_id' => $transaction->type === 'expense'
                    ? ($validated['envelope_id'] ?? null)
                    : null,
            ];

            if ($transaction->type === 'transfer') {
                $attributes['from_account_id'] = $validated['from_account_id'];
                $attributes['to_account_id'] = $validated['to_account_id'];
            } else {
                $attributes['account_id'] = $validated['account_id'];
            }

            $transaction->fill($attributes)->save();

            $ledger->applyEffects($transaction);
        });

        return back();
    }

    /**
     * Removes an entry and puts back everything it moved.
     */
    public function destroyTransaction(Request $request, Transaction $transaction, LedgerService $ledger): RedirectResponse
    {
        abort_if($transaction->user_id !== $request->user()->id, 403);

        DB::transaction(function () use ($transaction, $ledger) {
            $ledger->reverseEffects($transaction);
            $transaction->delete();
        });

        return back();
    }

    /**
     * Projects what a debt payment does before it is logged: how much of it is
     * this month's interest, how much actually comes off the balance, and where
     * that leaves the debt-free date. The Debt tab promises the date moves
     * before you commit, so the projection runs through the same engine the
     * Payoff Plan is drawn from.
     */
    public function previewDebtPayment(Request $request, PayoffEngine $engine): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'debt_id' => ['required', 'integer', $this->debtOwnershipRule($user->id)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $debts = $this->liveDebtsFor($user);
        $target = $debts->firstWhere('id', (int) $validated['debt_id']);

        $this->rejectIf($target === null, 'That debt has nothing left to pay.');

        $amount = round((float) $validated['amount'], 2);
        $split = $engine->splitPayment($target, $amount);

        $priorityDebtId = $settings->priority_debt_id;
        $before = $engine->simulate($debts, $settings->strategy, (float) $settings->extra_monthly, priorityDebtId: $priorityDebtId);

        $after = $engine->simulate(
            $debts->map(function (Debt $debt) use ($target, $split) {
                if ($debt->id !== $target->id) {
                    return $debt;
                }

                $paid = clone $debt;
                $paid->balance = $split['balanceAfter'];

                return $paid;
            }),
            $settings->strategy,
            (float) $settings->extra_monthly,
            priorityDebtId: $priorityDebtId,
        );

        return response()->json([
            'interest' => $split['interest'],
            'principal' => $split['principal'],
            'coversInterest' => $split['coversInterest'],
            'balanceBefore' => round((float) $target->balance, 2),
            'balanceAfter' => $split['balanceAfter'],
            'debtFreeBefore' => $this->horizonLabel($before['months']),
            'debtFreeAfter' => $this->horizonLabel($after['months']),
            'daysEarlier' => $this->daysBetweenHorizons($before['months'], $after['months']),
            'interestSaved' => round(
                (float) collect($before['perDebt'])->sum('totalInterest') - (float) collect($after['perDebt'])->sum('totalInterest'),
                2,
            ),
        ]);
    }

    /**
     * Debts the Debt tab can pay into, each carrying what the tab needs to
     * offer a sensible amount: the minimum, what the plan intends to send, and
     * whether it is the one the plan is currently aimed at.
     *
     * @return array<int, array{id: int, name: string, balance: float, monthly_rate: float|null, minimum_payment: float, kind: string|null, debt_type: string, due_day: int|null, linked_account_id: int|null, is_target: bool, planned_payment: float}>
     */
    private function debtProps(User $user, PayoffEngine $engine): array
    {
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $debts = $this->liveDebtsFor($user);

        if ($debts->isEmpty()) {
            return [];
        }

        $ordered = $engine->orderByPriority($debts, $settings->strategy, $settings->priority_debt_id);
        $targetId = $ordered->first()?->id;

        return $ordered->map(fn (Debt $debt) => [
            'id' => $debt->id,
            'name' => $debt->name,
            'balance' => round((float) $debt->balance, 2),
            'monthly_rate' => $debt->monthly_rate !== null ? (float) $debt->monthly_rate : null,
            'minimum_payment' => round((float) $debt->minimum_payment, 2),
            'kind' => $debt->kind,
            'debt_type' => $debt->debt_type,
            'due_day' => $debt->due_day,
            'linked_account_id' => $debt->linked_account_id,
            'is_target' => $debt->id === $targetId,
            // What the plan means to send this month: the minimum, plus the
            // standing extra if this is the debt the extra is aimed at.
            'planned_payment' => round(
                (float) $debt->minimum_payment + ($debt->id === $targetId ? (float) $settings->extra_monthly : 0.0),
                2,
            ),
        ])->values()->all();
    }

    /**
     * Debts with something still owed, balances taken from their linked account
     * where one exists so the tab never offers a stale figure.
     *
     * @return Collection<int, Debt>
     */
    private function liveDebtsFor(User $user): Collection
    {
        $debts = $user->debtFocusDebts()->with('linkedAccount')->get();

        foreach ($debts as $debt) {
            if ($debt->linkedAccount !== null) {
                $debt->balance = $debt->linkedAccount->balance;
            }
        }

        return $debts->filter(fn (Debt $debt) => (float) $debt->balance > 0)->values();
    }

    private function horizonLabel(?int $months): ?string
    {
        return $months !== null ? Carbon::today()->addMonthsNoOverflow($months)->format('M Y') : null;
    }

    /**
     * How much sooner the plan finishes, in whole days, so the sheet can say
     * "31 days earlier" rather than a bare month count.
     */
    private function daysBetweenHorizons(?int $before, ?int $after): ?int
    {
        if ($before === null || $after === null) {
            return null;
        }

        $today = Carbon::today();

        return (int) $today->copy()->addMonthsNoOverflow($after)
            ->diffInDays($today->copy()->addMonthsNoOverflow($before));
    }

    /**
     * Records a debt payment the same way the Budget page does: a transfer when
     * the debt is mirrored by a ledger account, otherwise an expense plus a
     * direct reduction. Either way the transaction is tagged with the debt so
     * it shows up as a payment against it.
     */
    private function recordDebtPayment(Debt $debt, Account $from, float $amount, ?string $description, Carbon $occurredAt, LedgerService $ledger): void
    {
        $label = $description ?? "Debt payment: {$debt->name}";

        if ($debt->linked_account_id !== null) {
            $transaction = $ledger->recordTransfer($from, $debt->linkedAccount, $amount, $label, $occurredAt);
        } else {
            $transaction = $ledger->recordExpense($from, $amount, $label, $occurredAt);
            $debt->update(['balance' => max(0.0, (float) $debt->balance - $amount)]);
        }

        $transaction->update(['debt_id' => $debt->id]);
    }

    private function debtOwnershipRule(int $userId): Exists
    {
        return Rule::exists('debt_focus_debts', 'id')->where('user_id', $userId);
    }

    /**
     * The account the rail has picked. Falls back to the first asset account,
     * so a new user lands on somewhere money actually flows through.
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function resolveSelectedAccount(Request $request, Collection $accounts): ?Account
    {
        $requested = $request->integer('account');

        if ($requested > 0) {
            $match = $accounts->firstWhere('id', $requested);

            if ($match !== null) {
                return $match;
            }
        }

        return $accounts->firstWhere('is_liability', false) ?? $accounts->first();
    }

    /**
     * @return array{0: string, 1: Carbon, 2: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        $validated = $request->validate([
            'range' => ['nullable', 'string', 'in:this_month,last_3,custom'],
            'from' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $range = $validated['range'] ?? 'this_month';

        if ($range === 'custom') {
            return ['custom', Carbon::parse($validated['from']), Carbon::parse($validated['to'])];
        }

        $end = Carbon::now()->endOfMonth();
        $start = $range === 'last_3'
            ? Carbon::now()->startOfMonth()->subMonthsNoOverflow(2)
            : Carbon::now()->startOfMonth();

        return [$range, $start, $end];
    }

    private function rangeLabel(string $range, Carbon $from, Carbon $to): string
    {
        return match ($range) {
            'last_3' => $from->format('M').' – '.$to->format('M Y'),
            'custom' => $from->format('j M Y').' – '.$to->format('j M Y'),
            default => $from->format('F Y'),
        };
    }

    /**
     * @param  array<int, array{label: string, month: string, value: float}>  $history
     */
    private function changeThisMonth(array $history): ?float
    {
        $count = count($history);

        if ($count < 2) {
            return null;
        }

        return round($history[$count - 1]['value'] - $history[$count - 2]['value'], 2);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function accountRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'institution' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:checking,savings,credit,cash,investment'],
            'is_liability' => ['required', 'boolean'],
            'balance' => ['required', 'numeric'],
        ];
    }

    private function accountOwnershipRule(int $userId): Exists
    {
        return Rule::exists('debt_focus_accounts', 'id')->where('user_id', $userId);
    }

    private function envelopeOwnershipRule(int $userId): Exists
    {
        return Rule::exists('debt_focus_envelopes', 'id')->where('user_id', $userId);
    }
}
