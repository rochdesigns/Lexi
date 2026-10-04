<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\TransactionRequest;
use App\Models\Money\Account;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionSource;
use App\Services\Money\Projection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TransactionsController extends Controller
{
    public function __construct(private Projection $projection) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $current = Carbon::today()->format('Y-m');
        $requested = (string) $request->query('month', $current);
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) === 1 ? $requested : $current;
        $first = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        $transactions = $user->transactions()
            ->counted()
            ->with(['account', 'debt', 'statementRow'])
            ->where('date', '>=', $first->toDateString())
            ->where('date', '<', $first->copy()->addMonth()->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
        $latest = $user->transactions()->counted()->where('source', TransactionSource::Manual)->latest('id')->first();
        $accounts = $user->accounts()->orderBy('name')->get();

        return Inertia::render('Money/Transactions', [
            'month' => $month,
            'currentMonth' => $current,
            'today' => Carbon::today()->toDateString(),
            'transactions' => $transactions->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'amount' => $transaction->amount,
                'category' => $transaction->category->value,
                'note' => $transaction->note,
                'source' => $transaction->source->value,
                'onStatement' => $transaction->statementRow !== null,
                'accountId' => $transaction->account_id,
                'debtId' => $transaction->debt_id,
                'paidWith' => data_get($transaction, 'account.name') ?? data_get($transaction, 'debt.name'),
            ])->values(),
            'categoryTotals' => $transactions
                ->groupBy(fn (Transaction $transaction): string => $transaction->category->value)
                ->map(fn ($group, string $category): array => ['category' => $category, 'total' => $group->sum('amount')])
                ->sortByDesc('total')
                ->values(),
            'total' => $transactions->sum('amount'),
            'accounts' => $accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'balance' => $account->balance,
                'isSpendable' => $account->is_spendable,
            ])->values(),
            'cards' => $user->debts()->where('type', DebtType::CreditCard)->with('cardDetail')->orderBy('name')->get()->map(fn (Debt $card): array => [
                'id' => $card->id,
                'name' => $card->name,
                'guard' => $this->projection->spendingGuard($card, $accounts),
            ])->values(),
            'lastPaidWith' => $latest === null ? null : ['accountId' => $latest->account_id, 'debtId' => $latest->debt_id],
        ]);
    }

    /**
     * Paying from an account takes the money out of its balance straight away.
     */
    public function store(TransactionRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $transaction = $request->user()->transactions()->create($request->transactionAttributes());

            $this->take($transaction);
        });

        return back();
    }

    public function update(TransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        abort_if($transaction->user_id !== $request->user()->id, 403);

        DB::transaction(function () use ($request, $transaction): void {
            $this->giveBack($transaction);
            $transaction->update($request->transactionAttributes());
            $this->take($transaction);
        });

        return back();
    }

    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_if($transaction->user_id !== $request->user()->id, 403);

        DB::transaction(function () use ($transaction): void {
            $this->giveBack($transaction);
            $transaction->delete();
        });

        return back();
    }

    private function take(Transaction $transaction): void
    {
        if ($transaction->account_id !== null) {
            Account::whereKey($transaction->account_id)->decrement('balance', $transaction->amount);
        }
    }

    private function giveBack(Transaction $transaction): void
    {
        if ($transaction->account_id !== null) {
            Account::whereKey($transaction->account_id)->increment('balance', $transaction->amount);
        }
    }
}
