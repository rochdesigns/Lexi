<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\TransactionRequest;
use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\CustomCategory;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtType;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionKind;
use App\Models\Money\TransactionSource;
use App\Services\Money\Ledger;
use App\Services\Money\Projection;
use App\Services\Money\SpendingLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TransactionsController extends Controller
{
    public function __construct(private Projection $projection, private Ledger $ledger, private SpendingLimits $spendingLimits) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $current = Carbon::today()->format('Y-m');
        $requested = (string) $request->query('month', $current);
        $all = $requested === 'all';
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $requested) === 1 ? $requested : $current;
        $first = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $accountFilter = $request->filled('account') ? $user->accounts()->find($request->integer('account'))?->id : null;
        $cardFilter = $accountFilter === null && $request->filled('card')
            ? $user->debts()->where('type', DebtType::CreditCard)->find($request->integer('card'))?->id
            : null;

        $transactions = $user->transactions()
            ->counted()
            ->with(['account', 'toAccount', 'debt', 'bill', 'statementRow', 'customCategory'])
            ->when($accountFilter, fn ($query) => $query->where(fn ($query) => $query->where('account_id', $accountFilter)->orWhere('to_account_id', $accountFilter)))
            ->when($cardFilter, fn ($query) => $query->where('debt_id', $cardFilter))
            ->when(! $all, fn ($query) => $query
                ->where('date', '>=', $first->toDateString())
                ->where('date', '<', $first->copy()->addMonth()->toDateString()))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
        $debtPayments = DebtPayment::query()
            ->whereIn('debt_id', $user->debts()->select('id'))
            ->whereNotNull('account_id')
            ->with(['account', 'debt'])
            ->when($accountFilter, fn ($query) => $query->where('account_id', $accountFilter))
            ->when($cardFilter, fn ($query) => $query->where('debt_id', $cardFilter))
            ->when(! $all, fn ($query) => $query
                ->where('date', '>=', $first->toDateString())
                ->where('date', '<', $first->copy()->addMonth()->toDateString()))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get();
        $latest = $user->transactions()->counted()->where('source', TransactionSource::Manual)->where('kind', TransactionKind::Expense)->latest('id')->first();
        $accounts = $user->accounts()->orderBy('name')->get();

        return Inertia::render('Money/Transactions', [
            'month' => $month,
            'all' => $all,
            'currentMonth' => $current,
            'accountFilter' => $accountFilter,
            'cardFilter' => $cardFilter,
            'today' => Carbon::today()->toDateString(),
            'transactions' => $transactions->map(fn (Transaction $transaction): array => [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'amount' => $transaction->amount,
                'category' => $transaction->category->value,
                'customCategoryId' => $transaction->custom_category_id,
                'categoryName' => $transaction->customCategory?->name,
                'note' => $transaction->note,
                'kind' => $transaction->kind->value,
                'source' => $transaction->source->value,
                'incomeSourceId' => $transaction->income_source_id,
                'payDate' => $transaction->pay_date?->toDateString(),
                'onStatement' => $transaction->statementRow !== null,
                'accountId' => $transaction->account_id,
                'toAccountId' => $transaction->to_account_id,
                'toAccountName' => $transaction->toAccount?->name,
                'debtId' => $transaction->debt_id,
                'billId' => $transaction->bill_id,
                'billName' => $transaction->bill?->name,
                'paidWith' => data_get($transaction, 'account.name') ?? data_get($transaction, 'debt.name'),
            ])->values(),
            'debtPayments' => $debtPayments->map(fn (DebtPayment $payment): array => [
                'id' => $payment->id,
                'date' => $payment->date->toDateString(),
                'amount' => $payment->amount,
                'debtName' => $payment->debt->name,
                'paidWith' => $payment->account?->name,
            ])->values(),
            'categoryTotals' => $transactions
                ->where('kind', TransactionKind::Expense)
                ->groupBy(fn (Transaction $transaction): string => $transaction->customCategory->name ?? $transaction->category->value)
                ->map(fn ($group, string $category): array => [
                    'category' => $category,
                    'total' => $group->sum('amount'),
                    'items' => $group
                        ->groupBy(fn (Transaction $transaction): string => $transaction->note ?: ($transaction->bill->name ?? 'No note'))
                        ->map(fn ($items, string $name): array => ['name' => $name, 'amount' => $items->sum('amount')])
                        ->sortByDesc('amount')
                        ->values(),
                ])
                ->sortByDesc('total')
                ->values(),
            'total' => $transactions->where('kind', TransactionKind::Expense)->sum('amount'),
            'received' => $transactions->where('kind', TransactionKind::Income)->sum('amount'),
            'incomeSources' => $user->incomeSources()->orderBy('name')->get()->map(fn ($source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'amount' => $source->amount,
            ])->values(),
            'customCategories' => $user->customCategories->map(fn (CustomCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])->values(),
            'spendingLimits' => array_values(array_filter(
                $this->spendingLimits->forMonth($user, Carbon::today()),
                fn (array $row): bool => $row['limit'] !== null,
            )),
            'prefill' => $this->prefill($request),
            'accounts' => $accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'balance' => $account->balance,
                'isSpendable' => $account->is_spendable,
            ])->values(),
            'bills' => $user->bills()->orderBy('name')->get()->map(fn (Bill $bill): array => [
                'id' => $bill->id,
                'name' => $bill->name,
                'amount' => $bill->amount,
                'accountId' => $bill->account_id,
                'debtId' => $bill->debt_id,
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
     * Money received lands in the account straight away, and spending from an
     * account comes out of it.
     */
    public function store(TransactionRequest $request): RedirectResponse
    {
        $this->ledger->recordTransaction($request->user(), $request->transactionAttributes());

        return back();
    }

    public function update(TransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        abort_if($transaction->user_id !== $request->user()->id, 403);

        $this->ledger->updateTransaction($transaction, $request->transactionAttributes());

        return back();
    }

    public function destroy(Request $request, Transaction $transaction): RedirectResponse
    {
        abort_if($transaction->user_id !== $request->user()->id, 403);

        DB::transaction(function () use ($transaction): void {
            $this->ledger->move($transaction, -1);
            $transaction->delete();
        });

        return back();
    }

    /**
     * Opens the form ready to record a pay day as received, or a bill as
     * paid, from the "Received?" and "Mark paid" links on the Overview.
     *
     * @return array{incomeSourceId: int|null, billId: int|null, name: string, amount: int, date: string}|null
     */
    private function prefill(Request $request): ?array
    {
        $user = $request->user();
        $source = $request->filled('income') ? $user->incomeSources()->find($request->integer('income')) : null;
        $bill = $source === null && $request->filled('bill') ? $user->bills()->find($request->integer('bill')) : null;
        $row = $source ?? $bill;

        if ($row === null) {
            return null;
        }

        $date = (string) $request->query('date', '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : Carbon::today()->toDateString();

        if ($bill !== null && Carbon::parse($date)->isSameMonth(Carbon::today())) {
            $date = Carbon::today()->toDateString();
        }

        return [
            'incomeSourceId' => $source?->id,
            'billId' => $bill?->id,
            'name' => $row->name,
            'amount' => $row->amount,
            'date' => $date,
        ];
    }
}
