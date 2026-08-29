<?php

namespace App\Http\Controllers;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\Transaction;
use App\Services\DebtFocus\LedgerService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = $request->user()->debtFocusAccounts()->orderBy('name')->get();

        $accountProps = $accounts->map(fn (Account $account) => [
            'id' => $account->id,
            'name' => $account->name,
            'institution' => $account->institution,
            'type' => $account->type,
            'is_liability' => $account->is_liability,
            'balance' => (float) $account->balance,
            'recent_transactions' => $this->recentTransactions($account),
            'linked_debt_names' => Debt::where('linked_account_id', $account->id)->pluck('name')->all(),
        ])->values();

        $envelopes = $request->user()->debtFocusEnvelopes()->where('active', true)->orderBy('name')->get()
            ->map(fn (Envelope $envelope) => ['id' => $envelope->id, 'name' => $envelope->name])
            ->values();

        return Inertia::render('Ledger', [
            'accounts' => $accountProps,
            'envelopes' => $envelopes,
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->accountRules());

        $request->user()->debtFocusAccounts()->create($validated);

        return back();
    }

    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $account->update($request->validate($this->accountRules()));

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
            'type' => ['required', 'string', 'in:income,expense,transfer'],
            'account_id' => ['required_unless:type,transfer', 'nullable', 'integer', $ownedAccount],
            'from_account_id' => ['required_if:type,transfer', 'nullable', 'integer', 'different:to_account_id', $ownedAccount],
            'to_account_id' => ['required_if:type,transfer', 'nullable', 'integer', $ownedAccount],
            'envelope_id' => ['nullable', 'integer', 'prohibited_unless:type,expense', $this->envelopeOwnershipRule($request->user()->id)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'description' => ['nullable', 'string', 'max:255'],
            'occurred_at' => ['required', 'date_format:Y-m-d'],
        ]);

        $amount = (float) $validated['amount'];
        $description = $validated['description'] ?? null;
        $occurredAt = Carbon::parse($validated['occurred_at']);

        match ($validated['type']) {
            'income' => $ledger->recordIncome(Account::findOrFail($validated['account_id']), $amount, $description, $occurredAt),
            'expense' => $ledger->recordExpense(Account::findOrFail($validated['account_id']), $amount, $description, $occurredAt, $validated['envelope_id'] ?? null),
            'transfer' => $ledger->recordTransfer(Account::findOrFail($validated['from_account_id']), Account::findOrFail($validated['to_account_id']), $amount, $description, $occurredAt),
        };

        return back();
    }

    /**
     * @return array<int, array{id: int, type: string, amount: float, description: string|null, occurred_at: string, direction: string, counterpart: string|null}>
     */
    private function recentTransactions(Account $account): array
    {
        $transactions = Transaction::query()
            ->involvingAccount($account->id)
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $transactions->map(function (Transaction $transaction) use ($account) {
            $direction = match (true) {
                $transaction->type === 'income' => 'in',
                $transaction->type === 'expense' => 'out',
                $transaction->from_account_id === $account->id => 'out',
                default => 'in',
            };

            $counterpart = match (true) {
                $transaction->type !== 'transfer' => null,
                $transaction->from_account_id === $account->id => $transaction->toAccount?->name ?? '(deleted account)',
                default => $transaction->fromAccount?->name ?? '(deleted account)',
            };

            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => (float) $transaction->amount,
                'description' => $transaction->description,
                'occurred_at' => $transaction->occurred_at->format('M j, Y'),
                'direction' => $direction,
                'counterpart' => $counterpart,
            ];
        })->all();
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
