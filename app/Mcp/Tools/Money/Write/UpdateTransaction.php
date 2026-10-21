<?php

namespace App\Mcp\Tools\Money\Write;

use App\Mcp\Tools\Money\WriteTool;
use App\Services\Money\Ledger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class UpdateTransaction extends WriteTool
{
    use TransactionRules;

    /**
     * The fields an edit can change, by the name the client uses and the column it lands in.
     *
     * @var array<string, string>
     */
    private const FIELDS = [
        'amountCentavos' => 'amount',
        'date' => 'date',
        'category' => 'category',
        'customCategoryId' => 'custom_category_id',
        'note' => 'note',
        'accountId' => 'account_id',
        'toAccountId' => 'to_account_id',
        'debtId' => 'debt_id',
        'billId' => 'bill_id',
        'incomeSourceId' => 'income_source_id',
        'payDate' => 'pay_date',
    ];

    protected string $name = 'update_transaction';

    protected string $description = 'Change one existing expense, income or transfer entry, by its id from list_transactions or record_transaction. Pass only the fields to change; the rest stay as they are, and pass null to clear an optional field such as note or billId. The kind cannot change. Account balances follow the edit: the old entry is undone and the new one applied. Same rules as record_transaction: an expense has a category or customCategoryId, and is paid from an account (accountId) or charged to a card (debtId), never both; passing one of those replaces the other.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'transactionId' => $schema->integer()->required()->description('The entry to change, from list_transactions.'),
            'amountCentavos' => $schema->integer()->min(1)->description('New amount in centavos: 100 centavos = 1 PHP.'),
            'date' => $schema->string()->format('date')->description('New date, YYYY-MM-DD.'),
            'category' => $schema->string()->description('Expenses only: a built-in category (food, groceries, transport, shopping, health, fun, home, other). Clears customCategoryId.'),
            'customCategoryId' => $schema->integer()->description('Expenses only: one of the user\'s own categories, from list_categories.'),
            'note' => $schema->string()->max(200)->description('New note, or null to clear it.'),
            'accountId' => $schema->integer()->description('The account the money comes out of (expense or transfer) or goes into (income). On an expense, clears debtId.'),
            'toAccountId' => $schema->integer()->description('Transfer only: the account the money goes into.'),
            'debtId' => $schema->integer()->description('Expenses only: the credit card it was charged to. Clears accountId.'),
            'billId' => $schema->integer()->description('Expenses only: the bill this pays, or null to unlink it.'),
            'incomeSourceId' => $schema->integer()->description('Income only: the income source this pay settles, or null.'),
            'payDate' => $schema->string()->format('date')->description('Income only: the pay day this settles, YYYY-MM-DD, or null.'),
        ];
    }

    public function handle(Request $request, Ledger $ledger): ResponseFactory|Response
    {
        if (($refusal = $this->refuseWithoutWriteScope($request)) !== null) {
            return $refusal;
        }

        $user = $this->userFor($request);
        $transactionId = $request->get('transactionId');
        $transaction = is_int($transactionId)
            ? $user->transactions()->counted()->whereIn('kind', ['expense', 'income', 'transfer'])->find($transactionId)
            : null;

        if ($transaction === null) {
            return Response::error('No expense, income or transfer with that transactionId. Get ids from list_transactions.');
        }

        $kind = $transaction->kind->value;
        $changes = Arr::only($request->all(), array_keys(self::FIELDS));

        if (array_key_exists('category', $changes) && ! array_key_exists('customCategoryId', $changes)) {
            $changes['customCategoryId'] = null;
        }

        if ($kind === 'expense' && ($changes['debtId'] ?? null) !== null && ! array_key_exists('accountId', $changes)) {
            $changes['accountId'] = null;
        }

        if ($kind === 'expense' && ($changes['accountId'] ?? null) !== null && ! array_key_exists('debtId', $changes)) {
            $changes['debtId'] = null;
        }

        $data = Validator::validate(
            [...$this->currentValues($transaction, $kind), ...$changes],
            $this->transactionRules($user, $kind, $ledger),
        );

        if ($kind === 'expense' && ($data['accountId'] ?? null) === null && ($data['debtId'] ?? null) === null) {
            return Response::error('An expense needs accountId or debtId.');
        }

        $ledger->updateTransaction($transaction, [
            'date' => $data['date'] ?? $transaction->date->toDateString(),
            'amount' => $data['amountCentavos'],
            ...$this->categoryAttributes($kind, $data),
            'note' => $data['note'] ?? null,
            'account_id' => $data['accountId'] ?? null,
            'to_account_id' => $data['toAccountId'] ?? null,
            'debt_id' => $data['debtId'] ?? null,
            'bill_id' => $data['billId'] ?? null,
            'income_source_id' => $data['incomeSourceId'] ?? null,
            'pay_date' => $data['payDate'] ?? null,
        ]);

        $transaction->refresh()->load('customCategory');

        return $this->respond('Updated '.$kind.'.', [
            'transaction' => $this->transactionRow($transaction),
            'accounts' => $user->accounts()
                ->whereIn('id', array_filter([$transaction->account_id, $transaction->to_account_id]))
                ->get()
                ->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'balance' => $account->balance])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The entry as it stands, in the client's field names, with only the
     * fields its kind allows so the kind's own rules can check the merge.
     *
     * @return array<string, mixed>
     */
    private function currentValues(mixed $transaction, string $kind): array
    {
        $values = [
            'amountCentavos' => $transaction->amount,
            'date' => $transaction->date->toDateString(),
            'note' => $transaction->note,
            'accountId' => $transaction->account_id,
        ];

        return match ($kind) {
            'transfer' => [...$values, 'toAccountId' => $transaction->to_account_id],
            'income' => [...$values, 'incomeSourceId' => $transaction->income_source_id, 'payDate' => $transaction->pay_date?->toDateString()],
            default => [
                ...$values,
                'category' => $transaction->category->value,
                'customCategoryId' => $transaction->custom_category_id,
                'debtId' => $transaction->debt_id,
                'billId' => $transaction->bill_id,
            ],
        };
    }
}
