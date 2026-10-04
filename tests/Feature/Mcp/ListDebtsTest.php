<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListDebts;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\PaymentPlan;
use App\Models\User;
use App\Services\Money\Projection;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

test('it lists debts with plans and recent payments', function (): void {
    $loan = Debt::factory()->for($this->user)->create(['name' => 'Bank loan', 'lender' => 'BPI', 'due_day' => 20, 'notes' => 'SECRET-NOTE']);
    $plan = PaymentPlan::factory()->for($loan)->create(['principal' => 3_000_000, 'monthly_amount' => 500_000, 'total_months' => 6, 'start_month' => '2026-10-01']);
    DebtPayment::factory()->for($loan)->create(['payment_plan_id' => $plan->id, 'amount' => 500_000, 'date' => '2026-10-02']);

    $projection = app(Projection::class);
    $today = Carbon::today();
    $loan->load(['cardDetail', 'paymentPlans.debt', 'payments', 'transactions']);
    $plan->load('debt');

    MoneyServer::actingAs($this->user)->tool(ListDebts::class)
        ->assertOk()
        ->assertSee(['Bank loan', 'BPI'])
        ->assertDontSee('SECRET-NOTE')
        ->assertStructuredContent(fn ($json) => $json
            ->has('debts', 1)
            ->has('debts.0', fn ($debt) => $debt
                ->where('name', 'Bank loan')
                ->where('type', 'loan')
                ->where('dueDay', 20)
                ->where('paidThisMonth', 500_000)
                ->where('dueThisMonth', $projection->dueThisMonth($loan, $today))
                ->where('totalRemaining', $projection->totalRemaining($loan, $today))
                ->where('nextDueDate', $projection->nextDueDate($loan, $today)?->toDateString())
                ->has('plans', 1)
                ->has('plans.0', fn ($p) => $p->where('monthlyAmount', 500_000)->where('totalMonths', 6)->where('startMonth', '2026-10')->where('endMonth', $projection->endMonth($plan)->format('Y-m'))->where('monthsLeft', $projection->monthsLeft($plan, $today))->where('remainingBalance', $projection->remainingBalance($plan, $today))->etc())
                ->has('payments', 1)
                ->where('paymentsTruncated', false)
                ->where('card', null)
                ->etc())
            ->where('monthlyTotal', 500_000)
            ->etc());
});

test('a credit card includes its statement details', function (): void {
    $card = Debt::factory()->for($this->user)->create(['name' => 'Visa', 'type' => 'credit_card', 'interest_type' => 'revolving', 'monthly_interest_rate' => 3.5, 'due_day' => 28]);
    CardDetail::factory()->for($card)->create(['credit_limit' => 10_000_000, 'revolving_balance' => 2_000_000, 'statement_day' => 10]);

    $projection = app(Projection::class);
    $today = Carbon::today();
    $card->load(['cardDetail', 'paymentPlans.debt', 'payments', 'transactions']);

    MoneyServer::actingAs($this->user)->tool(ListDebts::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('debts.0.card.closesOn', $projection->nextStatement($card, $today)['closes_on']->toDateString())
            ->where('debts.0.card.minimumDue', $projection->minimumDue($card, $today))
            ->where('debts.0.card.utilization', $projection->utilization($card, $today))
            ->where('debts.0.card.projectedStatement', $projection->projectedNextStatement($card, $today))
            ->where('debts.0.card.creditLimit', 10_000_000)
            ->where('debts.0.card.revolvingBalance', 2_000_000)
            ->where('debts.0.card.statementDay', 10)
            ->etc());
});

test('payments are capped at fifty per debt and flagged', function (): void {
    $loan = Debt::factory()->for($this->user)->create();
    DebtPayment::factory()->for($loan)->count(55)->create();

    MoneyServer::actingAs($this->user)->tool(ListDebts::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('debts.0.payments', 50)
            ->where('debts.0.paymentsTruncated', true)
            ->etc());
});

test('a user with no debts gets an empty list', function (): void {
    MoneyServer::actingAs($this->user)->tool(ListDebts::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('debts', [])->where('monthlyTotal', 0)->etc());
});
