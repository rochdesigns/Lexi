<?php

namespace App\Mcp\Tools\Money\Write;

use App\Mcp\Tools\Money\WriteTool;
use App\Services\Money\Ledger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class RecordTransaction extends WriteTool
{
    protected string $name = 'record_transaction';

    protected string $description = 'Record one expense, one amount of money received, or one transfer between the user\'s own accounts. An expense is paid from one of the user\'s accounts (accountId) or charged to one of their credit cards (debtId), never both. Income is paid into an account (accountId). A transfer (kind transfer) moves money from one account (accountId) to a different one (toAccountId). It adds a new entry and changes the account balance; it cannot edit or delete. Get ids from list_accounts, list_debts and list_income_sources, and do not call it twice for the same purchase.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['expense', 'income', 'transfer'])->description('expense (default), income, or transfer.'),
            'amountCentavos' => $schema->integer()->min(1)->required()->description('Amount in centavos: 100 centavos = 1 PHP, so PHP 250.50 is 25050.'),
            'date' => $schema->string()->format('date')->description('YYYY-MM-DD. Defaults to today.'),
            'category' => $schema->string()->description('Required for an expense: one of food, groceries, transport, shopping, health, fun, home, other. Not allowed for income or a transfer.'),
            'note' => $schema->string()->max(200)->description('Short note, such as the shop or who paid.'),
            'accountId' => $schema->integer()->description('The account the money comes out of (expense or transfer) or goes into (income).'),
            'toAccountId' => $schema->integer()->description('Transfer only: the account the money goes into. Must differ from accountId.'),
            'debtId' => $schema->integer()->description('The credit card an expense was charged to. Expenses only.'),
            'billId' => $schema->integer()->description('Expenses only: the bill this pays, from list_bills. Marks that month\'s bill paid, and uses the bill\'s account when accountId and debtId are left out.'),
            'incomeSourceId' => $schema->integer()->description('Income only: the income source this pay settles.'),
            'payDate' => $schema->string()->format('date')->description('Income only: the pay day this settles, YYYY-MM-DD.'),
        ];
    }

    public function handle(Request $request, Ledger $ledger): ResponseFactory|Response
    {
        if (($refusal = $this->refuseWithoutWriteScope($request)) !== null) {
            return $refusal;
        }

        $user = $this->userFor($request);
        $income = $request->get('kind') === 'income';
        $transfer = $request->get('kind') === 'transfer';
        $ownedAccount = Rule::exists('accounts', 'id')->where('user_id', $user->id);

        $data = $request->validate($transfer ? [
            'kind' => ['sometimes', 'in:transfer'],
            'amountCentavos' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['prohibited'],
            'note' => ['nullable', 'string', 'max:200'],
            'accountId' => ['required', 'integer', $ownedAccount],
            'toAccountId' => ['required', 'integer', 'different:accountId', $ownedAccount],
            'debtId' => ['prohibited'],
            'billId' => ['prohibited'],
            'incomeSourceId' => ['prohibited'],
            'payDate' => ['prohibited'],
        ] : ($income ? [
            'kind' => ['sometimes', 'in:income'],
            'amountCentavos' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['prohibited'],
            'note' => ['nullable', 'string', 'max:200'],
            'accountId' => ['required', 'integer', $ownedAccount],
            'toAccountId' => ['prohibited'],
            'debtId' => ['prohibited'],
            'billId' => ['prohibited'],
            'incomeSourceId' => ['nullable', 'integer', Rule::exists('income_sources', 'id')->where('user_id', $user->id)],
            'payDate' => ['nullable', 'date_format:Y-m-d'],
        ] : [
            'kind' => ['sometimes', 'in:expense'],
            'amountCentavos' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['required', Rule::in($ledger->expenseCategories())],
            'note' => ['nullable', 'string', 'max:200'],
            'accountId' => ['nullable', 'integer', 'required_without_all:debtId,billId', 'prohibits:debtId', $ownedAccount],
            'debtId' => ['nullable', 'integer', 'required_without_all:accountId,billId', 'prohibits:accountId', Rule::exists('debts', 'id')->where('user_id', $user->id)->where('type', 'credit_card')],
            'billId' => ['nullable', 'integer', Rule::exists('bills', 'id')->where('user_id', $user->id)],
            'toAccountId' => ['prohibited'],
            'incomeSourceId' => ['prohibited'],
            'payDate' => ['prohibited'],
        ]));

        $bill = ! $income && ! $transfer && isset($data['billId']) ? $user->bills()->whereKey($data['billId'])->first() : null;
        $accountId = $data['accountId'] ?? (isset($data['debtId']) ? null : $bill?->account_id);

        if (! $income && ! $transfer && $accountId === null && ! isset($data['debtId'])) {
            return Response::error('That bill has no account set. Pass accountId or debtId.');
        }

        $transaction = $ledger->recordTransaction($user, [
            'kind' => $transfer ? 'transfer' : ($income ? 'income' : 'expense'),
            'date' => $data['date'] ?? Carbon::today()->toDateString(),
            'amount' => $data['amountCentavos'],
            'category' => $transfer ? 'transfer' : ($income ? 'income' : $data['category']),
            'note' => $data['note'] ?? null,
            'account_id' => $accountId,
            'to_account_id' => $data['toAccountId'] ?? null,
            'debt_id' => $data['debtId'] ?? null,
            'bill_id' => $bill?->id,
            'income_source_id' => $data['incomeSourceId'] ?? null,
            'pay_date' => $data['payDate'] ?? null,
        ]);

        $account = $transaction->account_id === null ? null : $user->accounts()->find($transaction->account_id);
        $toAccount = $transaction->to_account_id === null ? null : $user->accounts()->find($transaction->to_account_id);

        return $this->respond($transfer ? 'Recorded transfer.' : ($income ? 'Recorded income.' : 'Recorded expense.'), [
            'transaction' => [
                'id' => $transaction->id,
                'kind' => $transaction->kind->value,
                'date' => $transaction->date->toDateString(),
                'amount' => $transaction->amount,
                'category' => $transaction->category->value,
                'note' => $transaction->note,
                'accountId' => $transaction->account_id,
                'toAccountId' => $transaction->to_account_id,
                'debtId' => $transaction->debt_id,
                'billId' => $transaction->bill_id,
            ],
            'accountBalanceAfter' => $account?->balance,
            'toAccountBalanceAfter' => $toAccount?->balance,
        ]);
    }
}
