<?php

use App\Models\Money\Account;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionSource;
use App\Models\User;
use App\Services\Money\Projection;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

function cardPayload(array $overrides = []): array
{
    return [
        'name' => 'Platinum',
        'type' => 'credit_card',
        'lender' => 'BDO',
        'interest_type' => 'revolving',
        'monthly_interest_rate' => 3.5,
        'due_day' => 28,
        'credit_limit' => 150000,
        'statement_day' => 10,
        'min_payment_percent' => 3,
        'min_payment_floor' => 500,
        'revolving_balance' => 1234.56,
        ...$overrides,
    ];
}

test('the debts page lists only my debts with their projections', function (): void {
    $card = Debt::factory()->for($this->user)->create(['type' => DebtType::CreditCard, 'interest_type' => InterestType::Revolving, 'due_day' => 28]);
    CardDetail::factory()->for($card)->create(['credit_limit' => 10_000_000, 'statement_day' => 10, 'revolving_balance' => 100_000]);
    PaymentPlan::factory()->for($card)->create(['principal' => 900_000, 'monthly_amount' => 150_000, 'total_months' => 6, 'start_month' => '2026-10-01']);
    Debt::factory()->create(['name' => 'Someone else']);

    $this->actingAs($this->user)
        ->get('/money/debts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Money/Debts')
            ->has('debts', 1)
            ->where('debts.0.id', $card->id)
            ->where('debts.0.dueThisMonth', 50_000)
            ->where('debts.0.totalRemaining', 1_000_000)
            ->where('debts.0.nextDueDate', '2026-10-28')
            ->where('debts.0.card.closesOn', '2026-11-10')
            ->where('debts.0.card.projectedStatement', 250_000)
            ->where('debts.0.card.utilization', 0.1)
            ->where('monthlyTotal', 50_000)
            ->has('rollOff', 24)
            ->where('rollOff.0.total', 150_000)
        );
});

test('a card is stored with its details as centavos', function (): void {
    $response = $this->actingAs($this->user)->post('/money/debts', cardPayload());

    $debt = Debt::with('cardDetail')->firstOrFail();
    $response->assertRedirect('/money/debts');

    expect($debt->user_id)->toBe($this->user->id)
        ->and($debt->type)->toBe(DebtType::CreditCard)
        ->and($debt->cardDetail->credit_limit)->toBe(15_000_000)
        ->and($debt->cardDetail->revolving_balance)->toBe(123_456)
        ->and($debt->cardDetail->min_payment_floor)->toBe(50_000)
        ->and($debt->outstanding_balance)->toBeNull();
});

test('a card needs its card fields and a due day', function (): void {
    $this->actingAs($this->user)
        ->post('/money/debts', cardPayload(['credit_limit' => null, 'statement_day' => null, 'due_day' => null, 'revolving_balance' => null]))
        ->assertSessionHasErrors(['credit_limit', 'statement_day', 'due_day', 'revolving_balance']);

    expect(Debt::count())->toBe(0);
});

test('an informal debt needs a balance but no schedule or due day', function (): void {
    $payload = ['name' => 'Ate Mia', 'type' => 'informal', 'interest_type' => 'none', 'outstanding_balance' => 5000, 'target_payoff_date' => '2027-01-31'];

    $this->actingAs($this->user)->post('/money/debts', $payload)->assertSessionHasNoErrors();

    $debt = Debt::with('cardDetail')->firstOrFail();
    expect($debt->outstanding_balance)->toBe(500_000)
        ->and($debt->target_payoff_date->toDateString())->toBe('2027-01-31')
        ->and($debt->cardDetail)->toBeNull();

    $this->actingAs($this->user)
        ->post('/money/debts', [...$payload, 'outstanding_balance' => null])
        ->assertSessionHasErrors('outstanding_balance');
});

test('a loan needs a due day and an interest rate when it accrues interest', function (): void {
    $this->actingAs($this->user)
        ->post('/money/debts', ['name' => 'SSS loan', 'type' => 'loan', 'interest_type' => 'amortized'])
        ->assertSessionHasErrors(['due_day', 'monthly_interest_rate']);
});

test('a card takes a total due and installments, and the rest becomes the revolving balance', function (): void {
    $payload = cardPayload([
        'revolving_balance' => null,
        'total_due' => 120000,
        'installments' => [
            ['description' => 'Laptop', 'monthly_amount' => 5000, 'start_month' => '2026-10', 'end_month' => '2027-03'],
            ['description' => 'Phone', 'monthly_amount' => 3000, 'start_month' => '2026-08', 'end_month' => '2027-05'],
        ],
    ]);

    $this->actingAs($this->user)->post('/money/debts', $payload)->assertSessionHasNoErrors();

    $debt = Debt::with(['cardDetail', 'paymentPlans'])->firstOrFail();
    $laptop = $debt->paymentPlans->firstWhere('description', 'Laptop');

    expect($debt->cardDetail->revolving_balance)->toBe(11_200_000)
        ->and($debt->paymentPlans)->toHaveCount(2)
        ->and($laptop->monthly_amount)->toBe(500_000)
        ->and($laptop->principal)->toBe(3_000_000)
        ->and($laptop->total_months)->toBe(6)
        ->and($laptop->start_month->toDateString())->toBe('2026-10-01')
        ->and($debt->paymentPlans->firstWhere('description', 'Phone')->start_month->toDateString())->toBe('2026-08-01')
        ->and($debt->paymentPlans->firstWhere('description', 'Phone')->total_months)->toBe(10);
});

test('the minimum due is the card minimum plus installments flagged as required', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'revolving_balance' => null,
        'total_due' => 12000,
        'min_payment_percent' => 5,
        'min_payment_floor' => 500,
        'installments' => [
            ['description' => 'Laptop', 'monthly_amount' => 1000, 'start_month' => '2026-10', 'end_month' => '2027-03', 'in_minimum' => true],
            ['description' => 'Phone', 'monthly_amount' => 2000, 'start_month' => '2026-10', 'end_month' => '2027-03', 'in_minimum' => false],
        ],
    ]))->assertSessionHasNoErrors();

    // 5% of the ₱9,000 revolving balance is ₱450, plus the ₱1,000 laptop. That is above the ₱500 lowest minimum.
    $this->actingAs($this->user)
        ->get('/money/debts')
        ->assertInertia(fn ($page) => $page->where('debts.0.card.minimumDue', 145_000));
});

test('the minimum due follows the HSBC rule: 1% of the balance plus installments and charges in full', function (): void {
    $installment = fn (string $name, float $monthly): array => [
        'description' => $name, 'monthly_amount' => $monthly, 'start_month' => '2026-01', 'end_month' => '2026-12', 'in_minimum' => true,
    ];

    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'statement_day' => 15,
        'revolving_balance' => null,
        'total_due' => 120363.03,
        'min_payment_percent' => 1,
        'min_payment_floor' => 500,
        'charges_due_in_full' => 933.92,
        'installments' => [$installment('Balance conversion', 4519.20), $installment('Eirmo 1', 1120), $installment('Eirmo 2', 1120), $installment('Eirmo 3', 1120)],
    ]))->assertSessionHasNoErrors();

    // 1% of (120,363.03 - 933.92 - 7,879.20) = 1,115.50, plus 7,879.20 of installments and the 933.92 finance charge.
    $this->actingAs($this->user)
        ->get('/money/debts')
        ->assertInertia(fn ($page) => $page
            ->where('debts.0.card.chargesDueInFull', 93_392)
            ->where('debts.0.card.revolvingBalance', 11_248_383)
            ->where('debts.0.card.minimumDue', 992_862));
});

test('the minimum due is never below the lowest minimum payment', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'revolving_balance' => 1000,
        'min_payment_percent' => 1,
        'min_payment_floor' => 500,
    ]))->assertSessionHasNoErrors();

    $this->actingAs($this->user)
        ->get('/money/debts')
        ->assertInertia(fn ($page) => $page->where('debts.0.card.minimumDue', 50_000));
});

test('a card with no minimum set and no flagged installments has no minimum due', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload(['min_payment_percent' => null, 'min_payment_floor' => null]));

    $this->actingAs($this->user)
        ->get('/money/debts')
        ->assertInertia(fn ($page) => $page->where('debts.0.card.minimumDue', null));
});

test('a card can set its due date as days after the statement instead of a due day', function (): void {
    $this->actingAs($this->user)
        ->post('/money/debts', cardPayload(['due_day' => null, 'due_days_after_statement' => 20]))
        ->assertSessionHasNoErrors();

    $debt = Debt::with('cardDetail')->firstOrFail();
    expect($debt->due_day)->toBeNull()
        ->and($debt->cardDetail->due_days_after_statement)->toBe(20);

    $this->actingAs($this->user)
        ->get('/money/debts')
        ->assertInertia(fn ($page) => $page
            ->where('debts.0.card.dueDaysAfterStatement', 20)
            ->where('debts.0.nextDueDate', '2026-10-30'));
});

test('a card needs either a due day or days after the statement', function (): void {
    $this->actingAs($this->user)
        ->post('/money/debts', cardPayload(['due_day' => null, 'due_days_after_statement' => null]))
        ->assertSessionHasErrors('due_day');
});

test('an installment that has not started or has ended is not taken off the total due', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'revolving_balance' => null,
        'total_due' => 10000,
        'installments' => [
            ['description' => 'Starts later', 'monthly_amount' => 3000, 'start_month' => '2027-02', 'end_month' => '2027-07'],
            ['description' => 'Already ended', 'monthly_amount' => 2000, 'start_month' => '2026-01', 'end_month' => '2026-09'],
            ['description' => 'Running', 'monthly_amount' => 1000, 'start_month' => '2026-08', 'end_month' => '2027-01'],
        ],
    ]))->assertSessionHasNoErrors();

    // The next statement closes 10 Nov 2026, so only the running one is on it.
    expect(Debt::with('cardDetail')->firstOrFail()->cardDetail->revolving_balance)->toBe(900_000);
});

test('an installment can be entered as a total and is spread over its months', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'revolving_balance' => null,
        'total_due' => 10000,
        'installments' => [
            ['description' => 'Fridge', 'total_amount' => 12000, 'start_month' => '2026-10', 'end_month' => '2027-09'],
        ],
    ]))->assertSessionHasNoErrors();

    $debt = Debt::with(['cardDetail', 'paymentPlans'])->firstOrFail();
    $plan = $debt->paymentPlans->firstOrFail();

    expect($plan->principal)->toBe(1_200_000)
        ->and($plan->monthly_amount)->toBe(100_000)
        ->and($plan->total_months)->toBe(12)
        ->and($debt->cardDetail->revolving_balance)->toBe(900_000);
});

test('an installment needs a monthly amount or a total', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'revolving_balance' => null,
        'total_due' => 10000,
        'installments' => [['description' => 'Fridge', 'start_month' => '2026-10', 'end_month' => '2027-09']],
    ]))->assertSessionHasErrors(['installments.0.monthly_amount', 'installments.0.total_amount']);
});

test('an installment with an amount borrowed carries add-on interest', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'revolving_balance' => null,
        'total_due' => 10000,
        'installments' => [
            ['description' => 'Conversion', 'principal' => 12000, 'monthly_amount' => 1120, 'start_month' => '2026-08', 'end_month' => '2027-07'],
            ['description' => 'Phone', 'monthly_amount' => 1000, 'start_month' => '2026-08', 'end_month' => '2027-07'],
        ],
    ]))->assertSessionHasNoErrors();

    $plans = Debt::firstOrFail()->paymentPlans;
    $conversion = $plans->firstWhere('description', 'Conversion');
    $phone = $plans->firstWhere('description', 'Phone');

    expect($conversion->interest_type)->toBe(InterestType::AddOn)
        ->and($conversion->principal)->toBe(1_200_000)
        ->and($conversion->monthly_amount)->toBe(112_000)
        ->and(app(Projection::class)->totalInterest($conversion->load('debt')))->toBe(144_000)
        ->and($phone->interest_type)->toBeNull()
        ->and($phone->principal)->toBe(1_200_000);
});

test('the amount borrowed cannot be more than the installment pays back', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'revolving_balance' => null,
        'total_due' => 10000,
        'installments' => [['description' => 'Conversion', 'principal' => 20000, 'monthly_amount' => 1120, 'start_month' => '2026-08', 'end_month' => '2027-07']],
    ]))->assertSessionHasErrors('installments.0.principal');

    expect(Debt::count())->toBe(0);
});

test('an installment cannot end before it starts', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'revolving_balance' => null,
        'total_due' => 12000,
        'installments' => [['description' => 'Laptop', 'monthly_amount' => 1000, 'start_month' => '2026-11', 'end_month' => '2026-09']],
    ]))->assertSessionHasErrors('installments.0.end_month');

    expect(Debt::count())->toBe(0);
});

test('installments cannot add up to more than the total due', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload([
        'revolving_balance' => null,
        'total_due' => 4000,
        'installments' => [['description' => 'Laptop', 'monthly_amount' => 5000, 'start_month' => '2026-10', 'end_month' => '2027-03']],
    ]))->assertSessionHasErrors('total_due');

    expect(Debt::count())->toBe(0);
});

test('a card with a total due and no installments is just a revolving balance', function (): void {
    $this->actingAs($this->user)
        ->post('/money/debts', cardPayload(['revolving_balance' => null, 'total_due' => 2500]))
        ->assertSessionHasNoErrors();

    expect(Debt::firstOrFail()->cardDetail->revolving_balance)->toBe(250_000)
        ->and(PaymentPlan::count())->toBe(0);
});

test('a new loan is saved with its first payment plan', function (): void {
    $this->actingAs($this->user)->post('/money/debts', [
        'name' => 'Laptop', 'type' => 'bnpl', 'interest_type' => 'none', 'due_day' => 5,
        'plan' => ['principal' => null, 'monthly_amount' => 1500, 'total_months' => 6, 'start_month' => '2026-11'],
    ])->assertSessionHasNoErrors();

    $plan = Debt::firstOrFail()->paymentPlans()->firstOrFail();
    expect($plan->monthly_amount)->toBe(150_000)
        ->and($plan->principal)->toBe(900_000)
        ->and($plan->total_months)->toBe(6)
        ->and($plan->start_month->toDateString())->toBe('2026-11-01');
});

test('an amortized loan works out its monthly amount from the amount borrowed', function (): void {
    $this->actingAs($this->user)->post('/money/debts', [
        'name' => 'SSS loan', 'type' => 'loan', 'interest_type' => 'amortized', 'monthly_interest_rate' => 1.5, 'due_day' => 5,
        'plan' => ['principal' => 10000, 'monthly_amount' => null, 'total_months' => 12, 'start_month' => '2026-11'],
    ])->assertSessionHasNoErrors();

    $plan = Debt::firstOrFail()->paymentPlans()->firstOrFail();
    expect($plan->principal)->toBe(1_000_000)
        ->and($plan->monthly_amount)->toBeGreaterThan(80_000);
});

test('a new loan or BNPL debt must come with an amount', function (): void {
    $base = ['name' => 'Laptop', 'type' => 'bnpl', 'interest_type' => 'none', 'due_day' => 5];

    $this->actingAs($this->user)->post('/money/debts', $base)->assertSessionHasErrors('plan');
    $this->actingAs($this->user)
        ->post('/money/debts', [...$base, 'plan' => ['total_months' => 6, 'start_month' => '2026-11']])
        ->assertSessionHasErrors('plan.monthly_amount');

    expect(Debt::count())->toBe(0);
});

test('editing a loan does not ask for or create a plan', function (): void {
    $debt = Debt::factory()->for($this->user)->create(['type' => DebtType::Loan, 'interest_type' => InterestType::None, 'due_day' => 5]);

    $this->actingAs($this->user)
        ->patch("/money/debts/{$debt->id}", ['name' => 'Renamed', 'type' => 'loan', 'interest_type' => 'none', 'due_day' => 5])
        ->assertSessionHasNoErrors();

    expect($debt->paymentPlans()->count())->toBe(0);
});

test('the debt page lists plans soonest ending first and the payment history', function (): void {
    $debt = Debt::factory()->for($this->user)->create(['interest_type' => InterestType::AddOn]);
    $long = PaymentPlan::factory()->for($debt)->create(['description' => 'Long', 'total_months' => 24, 'start_month' => '2026-01-01']);
    $short = PaymentPlan::factory()->for($debt)->create(['description' => 'Short', 'principal' => 270_000, 'monthly_amount' => 100_000, 'total_months' => 3, 'start_month' => '2026-09-01']);
    $debt->payments()->create(['date' => '2026-09-01', 'amount' => 100_000, 'is_extra' => false]);
    $debt->payments()->create(['date' => '2026-10-01', 'amount' => 50_000, 'is_extra' => true]);

    $this->actingAs($this->user)
        ->get("/money/debts/{$debt->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Money/Debt')
            ->where('plans.0.id', $short->id)
            ->where('plans.0.endMonth', '2026-11')
            ->where('plans.0.monthsLeft', 2)
            ->where('plans.0.interestType', 'add_on')
            ->where('plans.0.totalInterest', 30_000)
            ->where('plans.0.earlyPayoffSavesInterest', false)
            ->where('plans.1.id', $long->id)
            ->where('plans.1.endMonth', '2027-12')
            ->where('payments.0.date', '2026-10-01')
            ->where('payments.0.isExtra', true)
            ->where('payments.1.date', '2026-09-01')
            ->has('rollOff', 24)
        );
});

test('a card page groups its charges and payments by statement, newest first', function (): void {
    $card = Debt::factory()->for($this->user)->create(['type' => DebtType::CreditCard, 'interest_type' => InterestType::Revolving, 'due_day' => 28]);
    CardDetail::factory()->for($card)->create(['statement_day' => 10]);
    Transaction::factory()->for($this->user)->onCard($card)->create(['date' => '2026-09-05', 'amount' => 40_000, 'note' => 'Groceries']);
    Transaction::factory()->for($this->user)->onCard($card)->create(['date' => '2026-09-10', 'amount' => 10_000, 'note' => null]);
    $later = Transaction::factory()->for($this->user)->onCard($card)->create(['date' => '2026-10-12', 'amount' => 25_000, 'note' => 'Grab']);
    Transaction::factory()->for($this->user)->onCard($card)->create(['date' => '2026-10-12', 'amount' => 25_000, 'source' => TransactionSource::StatementImport, 'matched_transaction_id' => $later->id]);
    $card->payments()->create(['date' => '2026-10-01', 'amount' => 30_000, 'is_extra' => false]);
    Transaction::factory()->for($this->user)->create(['date' => '2026-10-12', 'amount' => 99_000]);

    $this->actingAs($this->user)
        ->get("/money/debts/{$card->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('statements', 3)
            ->where('statements.0.closesOn', '2026-11-10')
            ->where('statements.0.charged', 25_000)
            ->where('statements.0.paid', 0)
            ->has('statements.0.entries', 1)
            ->where('statements.0.entries.0.label', 'Grab')
            ->where('statements.1.closesOn', '2026-10-10')
            ->where('statements.1.opensOn', '2026-09-11')
            ->where('statements.1.charged', 0)
            ->where('statements.1.paid', 30_000)
            ->where('statements.1.entries.0.kind', 'payment')
            ->where('statements.2.closesOn', '2026-09-10')
            ->where('statements.2.charged', 50_000)
            ->where('statements.2.entries.0.label', 'Groceries')
            ->where('statements.2.entries.1.date', '2026-09-10')
            ->where('statements.2.entries.1.label', null)
            ->where('statements.2.entries.1.category', 'food')
            ->etc()
        );
});

test('a debt can be updated, turning a card into a loan drops its card details', function (): void {
    $this->actingAs($this->user)->post('/money/debts', cardPayload());
    $debt = Debt::firstOrFail();

    $this->actingAs($this->user)
        ->patch("/money/debts/{$debt->id}", cardPayload(['name' => 'Renamed', 'credit_limit' => 200000]))
        ->assertRedirect();
    expect($debt->fresh()->name)->toBe('Renamed')
        ->and($debt->cardDetail()->first()->credit_limit)->toBe(20_000_000);

    $this->actingAs($this->user)
        ->patch("/money/debts/{$debt->id}", ['name' => 'Renamed', 'type' => 'loan', 'interest_type' => 'none', 'due_day' => 5])
        ->assertSessionHasNoErrors();
    expect($debt->cardDetail()->exists())->toBeFalse();
});

test('deleting a debt deletes its plans, details and payments', function (): void {
    $debt = Debt::factory()->for($this->user)->create();
    CardDetail::factory()->for($debt)->create();
    PaymentPlan::factory()->for($debt)->create();
    $debt->payments()->create(['date' => '2026-10-01', 'amount' => 100, 'is_extra' => false]);

    $this->actingAs($this->user)->delete("/money/debts/{$debt->id}")->assertRedirect('/money/debts');

    expect(Debt::count())->toBe(0)->and(CardDetail::count())->toBe(0)->and(PaymentPlan::count())->toBe(0)
        ->and($debt->payments()->count())->toBe(0);
});

test('deleting a debt puts back what its payments took from accounts', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 500_000]);
    $debt = Debt::factory()->for($this->user)->create();
    $debt->payments()->create(['date' => '2026-10-01', 'amount' => 100_000, 'is_extra' => false, 'account_id' => $account->id]);
    $debt->payments()->create(['date' => '2026-10-02', 'amount' => 50_000, 'is_extra' => false, 'account_id' => $account->id]);
    $debt->payments()->create(['date' => '2026-10-03', 'amount' => 70_000, 'is_extra' => false]);
    $account->update(['balance' => 350_000]);

    $this->actingAs($this->user)->delete("/money/debts/{$debt->id}")->assertRedirect('/money/debts');

    expect($account->fresh()->balance)->toBe(500_000);
});

test('other people cannot see, change or delete my debt', function (): void {
    $debt = Debt::factory()->create();

    $this->actingAs($this->user)->get("/money/debts/{$debt->id}")->assertForbidden();
    $this->actingAs($this->user)->patch("/money/debts/{$debt->id}", cardPayload())->assertForbidden();
    $this->actingAs($this->user)->delete("/money/debts/{$debt->id}")->assertForbidden();

    expect(Debt::count())->toBe(1);
});
