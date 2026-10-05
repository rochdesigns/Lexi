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
class RecordDebtPayment extends WriteTool
{
    protected string $name = 'record_debt_payment';

    protected string $description = 'Record a payment the user has already made toward one of their debts. It adds to the debt\'s payment history, and lowers the balance of a debt that only tracks a balance (informal). Pass accountId, the account it was paid from, to take the amount out of that account\'s balance; without it no account changes. It cannot edit or delete. Get debtId from list_debts, and do not call it twice for the same payment.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'debtId' => $schema->integer()->required()->description('The debt that was paid, from list_debts.'),
            'amountCentavos' => $schema->integer()->min(1)->required()->description('Amount in centavos: 100 centavos = 1 PHP, so PHP 1,500 is 150000.'),
            'accountId' => $schema->integer()->description('The account the payment was paid from, from list_accounts. Its balance goes down by the amount. Leave out to leave every account as it is.'),
            'date' => $schema->string()->format('date')->description('YYYY-MM-DD the payment was made. Defaults to today.'),
            'isExtra' => $schema->boolean()->description('True if this is on top of the scheduled payment. Defaults to false.'),
        ];
    }

    public function handle(Request $request, Ledger $ledger): ResponseFactory|Response
    {
        if (($refusal = $this->refuseWithoutWriteScope($request)) !== null) {
            return $refusal;
        }

        $data = $request->validate([
            'debtId' => ['required', 'integer'],
            'amountCentavos' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'accountId' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->userFor($request)->id)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'isExtra' => ['nullable', 'boolean'],
        ]);

        $debt = $this->userFor($request)->debts()->whereKey((int) $data['debtId'])->first();

        if ($debt === null) {
            return Response::error('No debt with that id. Use list_debts to find the right debtId.');
        }

        $payment = $ledger->recordDebtPayment($debt, [
            'account_id' => $data['accountId'] ?? null,
            'date' => $data['date'] ?? Carbon::today()->toDateString(),
            'amount' => $data['amountCentavos'],
            'is_extra' => (bool) ($data['isExtra'] ?? false),
        ]);

        return $this->respond('Recorded payment.', [
            'payment' => [
                'id' => $payment->id,
                'debtId' => $debt->id,
                'debtName' => $debt->name,
                'accountId' => $payment->account_id,
                'date' => $payment->date->toDateString(),
                'amount' => $payment->amount,
                'isExtra' => $payment->is_extra,
            ],
            'outstandingBalanceAfter' => $debt->fresh()?->outstanding_balance,
        ]);
    }
}
