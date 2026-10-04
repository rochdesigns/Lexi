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

    protected string $description = 'Record one expense or one amount of money received. An expense is paid from one of the user\'s accounts (accountId) or charged to one of their credit cards (debtId), never both. Income is paid into an account (accountId). It adds a new entry and changes the account balance; it cannot edit or delete. Get ids from list_accounts, list_debts and list_income_sources, and do not call it twice for the same purchase.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()->enum(['expense', 'income'])->description('expense (default) or income.'),
            'amountCentavos' => $schema->integer()->min(1)->required()->description('Amount in centavos: 100 centavos = 1 PHP, so PHP 250.50 is 25050.'),
            'date' => $schema->string()->format('date')->description('YYYY-MM-DD. Defaults to today.'),
            'category' => $schema->string()->description('Required for an expense: one of food, groceries, transport, shopping, health, fun, home, other. Not allowed for income.'),
            'note' => $schema->string()->max(200)->description('Short note, such as the shop or who paid.'),
            'accountId' => $schema->integer()->description('The account the money comes out of (expense) or goes into (income).'),
            'debtId' => $schema->integer()->description('The credit card an expense was charged to. Expenses only.'),
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
        $ownedAccount = Rule::exists('accounts', 'id')->where('user_id', $user->id);

        $data = $request->validate($income ? [
            'kind' => ['sometimes', 'in:income'],
            'amountCentavos' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['prohibited'],
            'note' => ['nullable', 'string', 'max:200'],
            'accountId' => ['required', 'integer', $ownedAccount],
            'debtId' => ['prohibited'],
            'incomeSourceId' => ['nullable', 'integer', Rule::exists('income_sources', 'id')->where('user_id', $user->id)],
            'payDate' => ['nullable', 'date_format:Y-m-d'],
        ] : [
            'kind' => ['sometimes', 'in:expense'],
            'amountCentavos' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'category' => ['required', Rule::in($ledger->expenseCategories())],
            'note' => ['nullable', 'string', 'max:200'],
            'accountId' => ['nullable', 'integer', 'required_without:debtId', 'prohibits:debtId', $ownedAccount],
            'debtId' => ['nullable', 'integer', 'required_without:accountId', 'prohibits:accountId', Rule::exists('debts', 'id')->where('user_id', $user->id)->where('type', 'credit_card')],
            'incomeSourceId' => ['prohibited'],
            'payDate' => ['prohibited'],
        ]);

        $transaction = $ledger->recordTransaction($user, [
            'kind' => $income ? 'income' : 'expense',
            'date' => $data['date'] ?? Carbon::today()->toDateString(),
            'amount' => $data['amountCentavos'],
            'category' => $income ? 'income' : $data['category'],
            'note' => $data['note'] ?? null,
            'account_id' => $data['accountId'] ?? null,
            'debt_id' => $data['debtId'] ?? null,
            'income_source_id' => $data['incomeSourceId'] ?? null,
            'pay_date' => $data['payDate'] ?? null,
        ]);

        $account = $transaction->account_id === null ? null : $user->accounts()->find($transaction->account_id);

        return $this->respond(($income ? 'Recorded income.' : 'Recorded expense.'), [
            'transaction' => [
                'id' => $transaction->id,
                'kind' => $transaction->kind->value,
                'date' => $transaction->date->toDateString(),
                'amount' => $transaction->amount,
                'category' => $transaction->category->value,
                'note' => $transaction->note,
                'accountId' => $transaction->account_id,
                'debtId' => $transaction->debt_id,
            ],
            'accountBalanceAfter' => $account?->balance,
        ]);
    }
}
