<?php

namespace App\Mcp\Tools\Money\Write;

use App\Http\Requests\Money\DebtRequest;
use App\Mcp\Tools\Money\WriteTool;
use App\Services\Money\Ledger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld(false)]
class CreateDebt extends WriteTool
{
    /**
     * Where each argument goes in the debt form, which this tool validates
     * through, so the rules cannot drift from the app's. Amount arguments
     * arrive in centavos and the form takes pesos.
     */
    private const FIELDS = [
        'name' => 'name',
        'type' => 'type',
        'lender' => 'lender',
        'interestType' => 'interest_type',
        'monthlyInterestRate' => 'monthly_interest_rate',
        'dueDay' => 'due_day',
        'dueDaysAfterStatement' => 'due_days_after_statement',
        'notes' => 'notes',
        'targetPayoffDate' => 'target_payoff_date',
        'statementDay' => 'statement_day',
        'minPaymentPercent' => 'min_payment_percent',
    ];

    private const AMOUNT_FIELDS = [
        'outstandingBalanceCentavos' => 'outstanding_balance',
        'creditLimitCentavos' => 'credit_limit',
        'minPaymentFloorCentavos' => 'min_payment_floor',
        'chargesDueInFullCentavos' => 'charges_due_in_full',
        'revolvingBalanceCentavos' => 'revolving_balance',
        'totalDueCentavos' => 'total_due',
    ];

    protected string $name = 'create_debt';

    protected string $description = 'Add a new debt: a credit card, a loan, buy-now-pay-later (bnpl) or an informal debt owed to a person. It adds a new debt and cannot edit or delete one; do not call it twice for the same debt, and check list_debts first. Required for every type: name, type, interestType and, except for informal debts, dueDay. Informal: also outstandingBalanceCentavos. Credit card: also creditLimitCentavos, statementDay and either revolvingBalanceCentavos or totalDueCentavos (the whole statement, from which its installments are taken). Loan or bnpl: also a plan with totalMonths, startMonth and monthlyAmountCentavos (principalCentavos too for amortized and add_on). interestType amortized and revolving also need monthlyInterestRate.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->max(100)->description('What the user calls the debt, such as "BDO Mastercard" or "Loan from Ate Mia".'),
            'type' => $schema->string()->required()->enum(['credit_card', 'loan', 'bnpl', 'informal'])->description('The kind of debt.'),
            'interestType' => $schema->string()->required()->enum(['none', 'amortized', 'add_on', 'revolving'])->description('How interest works: none; amortized (a loan with a rate); add_on (flat interest on the amount borrowed); revolving (a card carrying a balance).'),
            'lender' => $schema->string()->max(100)->description('Who it is owed to.'),
            'monthlyInterestRate' => $schema->number()->min(0)->max(100)->description('Interest per month as a percent, so 3.5 means 3.5% a month. Required for amortized and revolving.'),
            'dueDay' => $schema->integer()->min(1)->max(31)->description('Day of the month a payment is due. Not needed for informal debts, or for a card that sets dueDaysAfterStatement instead.'),
            'dueDaysAfterStatement' => $schema->integer()->min(1)->max(60)->description('Credit card only: due this many days after the statement closes, instead of a fixed dueDay.'),
            'notes' => $schema->string()->max(2000),
            'outstandingBalanceCentavos' => $schema->integer()->min(0)->description('Informal debts only: what is still owed, in centavos (100 centavos = 1 PHP).'),
            'targetPayoffDate' => $schema->string()->format('date')->description('Informal debts only: YYYY-MM-DD the user wants it cleared by.'),
            'creditLimitCentavos' => $schema->integer()->min(0)->description('Credit card only.'),
            'statementDay' => $schema->integer()->min(1)->max(31)->description('Credit card only: the day of the month the statement closes.'),
            'minPaymentPercent' => $schema->number()->min(0)->max(100)->description('Credit card only: minimum payment as a percent of the balance.'),
            'minPaymentFloorCentavos' => $schema->integer()->min(0)->description('Credit card only: the smallest minimum payment.'),
            'chargesDueInFullCentavos' => $schema->integer()->min(0)->description('Credit card only: charges on the statement that must be paid in full.'),
            'revolvingBalanceCentavos' => $schema->integer()->min(0)->description('Credit card only: the balance carried over and charged interest. Give this or totalDueCentavos.'),
            'totalDueCentavos' => $schema->integer()->min(0)->description('Credit card only: the latest statement\'s total due. The revolving balance is that minus the installments running on it.'),
            'installments' => $schema->array()->max(20)->items($schema->object([
                'description' => $schema->string()->required()->max(150),
                'monthlyAmountCentavos' => $schema->integer()->min(1)->description('What it costs each month. Give this or totalAmountCentavos.'),
                'totalAmountCentavos' => $schema->integer()->min(1)->description('What it costs in all, interest included.'),
                'principalCentavos' => $schema->integer()->min(1)->description('The amount borrowed, if there is interest.'),
                'startMonth' => $schema->string()->required()->description('YYYY-MM of the first payment.'),
                'endMonth' => $schema->string()->required()->description('YYYY-MM of the last payment.'),
                'inMinimum' => $schema->boolean()->description('True if the card counts it within its minimum payment.'),
            ]))->description('Credit card only: installment plans already running on the card.'),
            'plan' => $schema->object([
                'totalMonths' => $schema->integer()->required()->min(1)->max(360),
                'startMonth' => $schema->string()->required()->description('YYYY-MM of the first payment.'),
                'monthlyAmountCentavos' => $schema->integer()->min(1)->description('Required unless amortized, where it is worked out.'),
                'principalCentavos' => $schema->integer()->min(1)->description('The amount borrowed. Required for amortized and add_on.'),
            ])->description('Loan or bnpl only: its first payment plan.'),
        ];
    }

    public function handle(Request $request, Ledger $ledger): ResponseFactory|Response
    {
        if (($refusal = $this->refuseWithoutWriteScope($request)) !== null) {
            return $refusal;
        }

        $user = $this->userFor($request);
        $form = DebtRequest::create('/', 'POST', $this->formInput($request->all()));
        $form->setContainer(app())->setRedirector(app('redirect'))->setUserResolver(fn () => $user);
        $form->validateResolved();

        $debt = $ledger->createDebt($user, $form);

        return $this->respond('Added debt "'.$debt->name.'".', [
            'debt' => [
                'id' => $debt->id,
                'name' => $debt->name,
                'type' => $debt->type->value,
                'interestType' => $debt->interest_type->value,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function formInput(array $arguments): array
    {
        $input = [];

        foreach (self::FIELDS as $argument => $field) {
            if (isset($arguments[$argument])) {
                $input[$field] = $arguments[$argument];
            }
        }

        foreach (self::AMOUNT_FIELDS as $argument => $field) {
            if (isset($arguments[$argument])) {
                $input[$field] = $this->pesos($arguments[$argument]);
            }
        }

        if (is_array($arguments['plan'] ?? null)) {
            $plan = $arguments['plan'];
            $input['plan'] = array_filter([
                'total_months' => $plan['totalMonths'] ?? null,
                'start_month' => $plan['startMonth'] ?? null,
                'monthly_amount' => isset($plan['monthlyAmountCentavos']) ? $this->pesos($plan['monthlyAmountCentavos']) : null,
                'principal' => isset($plan['principalCentavos']) ? $this->pesos($plan['principalCentavos']) : null,
            ], fn ($value): bool => $value !== null);
        }

        if (is_array($arguments['installments'] ?? null)) {
            $input['installments'] = array_map(fn ($row): array => is_array($row) ? array_filter([
                'description' => $row['description'] ?? null,
                'monthly_amount' => isset($row['monthlyAmountCentavos']) ? $this->pesos($row['monthlyAmountCentavos']) : null,
                'total_amount' => isset($row['totalAmountCentavos']) ? $this->pesos($row['totalAmountCentavos']) : null,
                'principal' => isset($row['principalCentavos']) ? $this->pesos($row['principalCentavos']) : null,
                'start_month' => $row['startMonth'] ?? null,
                'end_month' => $row['endMonth'] ?? null,
                'in_minimum' => $row['inMinimum'] ?? null,
            ], fn ($value): bool => $value !== null) : [], $arguments['installments']);
        }

        return $input;
    }

    private function pesos(mixed $centavos): float
    {
        return (int) $centavos / 100;
    }
}
