<?php

use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtStatus;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Services\Money\Projection;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->projection = new Projection;
});

/** A card with two 0% installments and a revolving balance of 1,000.00. */
function cardFixture(array $card = [], array $debt = []): Debt
{
    $debt = Debt::factory()->create([
        'name' => 'Platinum',
        'type' => DebtType::CreditCard,
        'interest_type' => InterestType::Revolving,
        'monthly_interest_rate' => 3.5,
        'due_day' => 28,
        ...$debt,
    ]);
    CardDetail::factory()->for($debt)->create([
        'credit_limit' => 10_000_000,
        'statement_day' => 10,
        'revolving_balance' => 100_000,
        ...$card,
    ]);

    // 1,000 x 12 from Apr 2026: ends Mar 2027, 6 months left.
    PaymentPlan::factory()->for($debt)->create(['principal' => 1_200_000, 'monthly_amount' => 100_000, 'total_months' => 12, 'start_month' => '2026-04-01', 'in_minimum' => true]);
    // 500 x 6 from Sep 2026: ends Feb 2027, 5 months left.
    PaymentPlan::factory()->for($debt)->create(['principal' => 300_000, 'monthly_amount' => 50_000, 'total_months' => 6, 'start_month' => '2026-09-01']);

    return $debt->load(['cardDetail', 'paymentPlans']);
}

/** 12,000.00 at 1% a month over 12 months, first payment in Sep 2026. */
function amortizedLoanFixture(): Debt
{
    $debt = Debt::factory()->create(['name' => 'Salary loan', 'type' => DebtType::Loan, 'interest_type' => InterestType::Amortized, 'monthly_interest_rate' => 1.0]);
    PaymentPlan::factory()->for($debt)->create(['principal' => 1_200_000, 'monthly_amount' => 106_619, 'total_months' => 12, 'start_month' => '2026-09-01']);

    return $debt->load(['cardDetail', 'paymentPlans']);
}

/** 10,000.00 borrowed, 1,900.00 x 6 repaid (1,400.00 fixed interest), first payment in Aug 2026. */
function addOnLoanFixture(): Debt
{
    $debt = Debt::factory()->create(['name' => 'Appliance loan', 'type' => DebtType::Bnpl, 'interest_type' => InterestType::AddOn]);
    PaymentPlan::factory()->for($debt)->create(['principal' => 1_000_000, 'monthly_amount' => 190_000, 'total_months' => 6, 'start_month' => '2026-08-01']);

    return $debt->load(['cardDetail', 'paymentPlans']);
}

/** 5,000.00 owed to family, to be cleared by the end of January 2027. */
function informalFixture(array $overrides = []): Debt
{
    return Debt::factory()->create([
        'name' => 'Ate Mia',
        'type' => DebtType::Informal,
        'outstanding_balance' => 500_000,
        'target_payoff_date' => '2027-01-31',
        'due_day' => null,
        ...$overrides,
    ])->load(['cardDetail', 'paymentPlans']);
}

test('a 0% plan reports its end month, months left and remaining balance', function (): void {
    [$running, $newer] = cardFixture()->paymentPlans->all();

    expect($this->projection->endMonth($running)->format('Y-m'))->toBe('2027-03')
        ->and($this->projection->monthsLeft($running, now()))->toBe(6)
        ->and($this->projection->remainingBalance($running, now()))->toBe(600_000)
        ->and($this->projection->monthsLeft($newer, now()))->toBe(5)
        ->and($this->projection->remainingBalance($newer, now()))->toBe(250_000);
});

test('a plan that has not started owes its full amount, and a finished one owes nothing', function (): void {
    $plan = cardFixture()->paymentPlans->first();

    expect($this->projection->monthsLeft($plan, Carbon::parse('2026-01-01')))->toBe(12)
        ->and($this->projection->remainingBalance($plan, Carbon::parse('2026-01-01')))->toBe(1_200_000)
        ->and($this->projection->monthsLeft($plan, Carbon::parse('2027-03-01')))->toBe(1)
        ->and($this->projection->monthsLeft($plan, Carbon::parse('2027-04-01')))->toBe(0)
        ->and($this->projection->remainingBalance($plan, Carbon::parse('2027-04-01')))->toBe(0);
});

test('a plan paid off early owes and bills nothing', function (): void {
    $plan = cardFixture()->paymentPlans->first();
    $plan->update(['status' => DebtStatus::PaidOff]);

    expect($this->projection->monthsLeft($plan, now()))->toBe(0)
        ->and($this->projection->remainingBalance($plan, now()))->toBe(0)
        ->and($this->projection->paymentFor($plan, now()))->toBe(0);
});

test('an amortized loan splits each payment into interest and principal', function (): void {
    $loan = amortizedLoanFixture();
    $plan = $loan->paymentPlans->first();

    expect($this->projection->amortizedPayment(1_200_000, 1.0, 12))->toBe(106_619);

    // After the September payment: 12,000.00 x 1.01 - 1,066.19 = 11,053.81 outstanding.
    expect($this->projection->monthsLeft($plan, now()))->toBe(11)
        ->and($this->projection->remainingBalance($plan, now()))->toBe(1_105_381);

    // October: 110.54 interest, 955.65 principal.
    expect($this->projection->scheduleRow($plan, now()))->toMatchArray([
        'payment' => 106_619,
        'interest' => 11_054,
        'principal' => 95_565,
        'balance' => 1_105_381 - 95_565,
    ]);
});

test('an amortized schedule clears the principal exactly', function (): void {
    $plan = amortizedLoanFixture()->paymentPlans->first();
    $schedule = $this->projection->schedule($plan);

    expect($schedule)->toHaveCount(12)
        ->and(array_sum(array_column($schedule, 'principal')))->toBe(1_200_000)
        ->and($schedule[11]['balance'])->toBe(0)
        ->and(abs($schedule[11]['payment'] - 106_619))->toBeLessThan(20)
        ->and($this->projection->totalInterest($plan))->toBe(array_sum(array_column($schedule, 'interest')))
        ->and($this->projection->earlyPayoffSavesInterest($plan))->toBeTrue();
});

test('an add-on loan fixes its interest upfront', function (): void {
    $plan = addOnLoanFixture()->paymentPlans->first();

    // Aug and Sep are paid, so Oct to Jan remain: 4 x 1,900.00.
    expect($this->projection->monthsLeft($plan, now()))->toBe(4)
        ->and($this->projection->remainingBalance($plan, now()))->toBe(760_000)
        ->and($this->projection->totalInterest($plan))->toBe(140_000)
        ->and($this->projection->earlyPayoffSavesInterest($plan))->toBeFalse();

    $schedule = $this->projection->schedule($plan);
    expect(array_sum(array_column($schedule, 'interest')))->toBe(140_000)
        ->and(array_sum(array_column($schedule, 'principal')))->toBe(1_000_000);
});

test('a plan inherits its interest type from the debt unless it sets its own', function (): void {
    $loan = amortizedLoanFixture();
    $plan = $loan->paymentPlans->first();

    expect($this->projection->interestTypeOf($plan))->toBe(InterestType::Amortized);

    $plan->update(['interest_type' => InterestType::None]);

    expect($this->projection->interestTypeOf($plan->fresh()->load('debt')))->toBe(InterestType::None)
        ->and($this->projection->totalInterest($plan->fresh()->load('debt')))->toBe(0);
});

test('an informal debt spreads its balance up to the target date', function (): void {
    $debt = informalFixture();

    // October to January is 4 months.
    expect($this->projection->totalRemaining($debt, now()))->toBe(500_000)
        ->and($this->projection->dueThisMonth($debt, now()))->toBe(125_000)
        ->and($this->projection->billedIn($debt, Carbon::parse('2027-01-01'), now()))->toBe(125_000)
        ->and($this->projection->billedIn($debt, Carbon::parse('2027-02-01'), now()))->toBe(0);
});

test('an informal debt with no target date asks for nothing each month', function (): void {
    $debt = informalFixture(['target_payoff_date' => null]);

    expect($this->projection->totalRemaining($debt, now()))->toBe(500_000)
        ->and($this->projection->dueThisMonth($debt, now()))->toBe(0);
});

test('an informal debt past its target date wants everything this month', function (): void {
    $debt = informalFixture(['target_payoff_date' => '2026-06-30']);

    expect($this->projection->dueThisMonth($debt, now()))->toBe(500_000)
        ->and($this->projection->billedIn($debt, Carbon::parse('2026-11-01'), now()))->toBe(0);
});

test('a debt totals its plans, outstanding balance and revolving balance', function (): void {
    expect($this->projection->totalRemaining(cardFixture(), now()))->toBe(600_000 + 250_000 + 100_000)
        ->and($this->projection->totalRemaining(amortizedLoanFixture(), now()))->toBe(1_105_381)
        ->and($this->projection->totalRemaining(addOnLoanFixture(), now()))->toBe(760_000)
        ->and($this->projection->totalRemaining(informalFixture(), now()))->toBe(500_000);
});

test('a paid off debt owes and bills nothing', function (): void {
    $debt = cardFixture();
    $debt->update(['status' => DebtStatus::PaidOff]);

    expect($this->projection->totalRemaining($debt, now()))->toBe(0)
        ->and($this->projection->dueThisMonth($debt, now()))->toBe(0)
        ->and($this->projection->nextDueDate($debt, now()))->toBeNull();
});

test('the projected statement is the plans billed that month plus the revolving balance', function (): void {
    $card = cardFixture();

    // Past the 10th, so the next statement closes on 10 Nov carrying 1,000 + 500 of
    // installments plus the 1,000 revolving balance.
    expect($this->projection->projectedNextStatement($card, now()))->toBe(250_000)
        ->and($this->projection->nextStatement($card, now())['closes_on']->toDateString())->toBe('2026-11-10')
        ->and($this->projection->nextDueDate($card, now())->toDateString())->toBe('2026-10-28');
});

test('a statement that has not closed yet this month is still the next one', function (): void {
    $card = cardFixture();

    expect($this->projection->nextStatement($card, Carbon::parse('2026-10-10'))['closes_on']->toDateString())->toBe('2026-10-10')
        ->and($this->projection->nextStatement($card, Carbon::parse('2026-10-01'))['closes_on']->toDateString())->toBe('2026-10-10');
});

test('a due day earlier than the statement day falls in the following month', function (): void {
    $card = cardFixture(['statement_day' => 25], ['due_day' => 10]);

    $statement = $this->projection->nextStatement($card, now());

    expect($statement['closes_on']->toDateString())->toBe('2026-10-25')
        ->and($statement['due_on']->toDateString())->toBe('2026-11-10');
});

test('a card due a set number of days after the statement ignores the fixed due day', function (): void {
    $card = cardFixture(['statement_day' => 20, 'due_days_after_statement' => 20], ['due_day' => null]);

    $statement = $this->projection->nextStatement($card, now());

    expect($statement['closes_on']->toDateString())->toBe('2026-10-20')
        ->and($statement['due_on']->toDateString())->toBe('2026-11-09')
        ->and($this->projection->nextDueDate($card, now())->toDateString())->toBe('2026-11-09');
});

test('the next due date is the statement already closed until its due date passes', function (): void {
    // Closes on the 20th, due 20 days later: the 20 Oct statement is due 9 Nov.
    $card = cardFixture(['statement_day' => 20, 'due_days_after_statement' => 20], ['due_day' => null]);

    expect($this->projection->nextDueDate($card, Carbon::parse('2026-10-25'))->toDateString())->toBe('2026-11-09')
        ->and($this->projection->nextDueDate($card, Carbon::parse('2026-11-09'))->toDateString())->toBe('2026-11-09')
        ->and($this->projection->nextDueDate($card, Carbon::parse('2026-11-10'))->toDateString())->toBe('2026-12-10')
        ->and($this->projection->nextDueDate($card, Carbon::parse('2026-11-21'))->toDateString())->toBe('2026-12-10');
});

test('a fixed due day also waits out the statement that has closed', function (): void {
    $card = cardFixture(['statement_day' => 25], ['due_day' => 10]);

    expect($this->projection->nextDueDate($card, Carbon::parse('2026-10-28'))->toDateString())->toBe('2026-11-10')
        ->and($this->projection->nextDueDate($card, Carbon::parse('2026-11-11'))->toDateString())->toBe('2026-12-10');
});

test('installments already billed on a statement still to be paid count as owed', function (): void {
    // Cut-off 15 Sep, due 6 Oct: on 3 Oct the September payments (1,000 + 500) are billed but not yet paid.
    $card = cardFixture(['statement_day' => 15, 'due_days_after_statement' => 21], ['due_day' => null]);

    expect($this->projection->billedInstallments($card, Carbon::parse('2026-10-03')))->toBe(150_000)
        ->and($this->projection->totalRemaining($card, Carbon::parse('2026-10-03')))->toBe(1_100_000)
        ->and($this->projection->utilization($card, Carbon::parse('2026-10-03')))->toBe(0.11)
        ->and($this->projection->billedInstallments($card, Carbon::parse('2026-10-06')))->toBe(150_000)
        ->and($this->projection->billedInstallments($card, Carbon::parse('2026-10-07')))->toBe(0)
        ->and($this->projection->totalRemaining($card, Carbon::parse('2026-10-07')))->toBe(950_000);
});

test('a statement billed this month is already in what is left on the installments', function (): void {
    // Cut-off 10 Oct: on 15 Oct the October payments are billed, and still counted as remaining.
    $card = cardFixture(['statement_day' => 10, 'due_days_after_statement' => 21], ['due_day' => null]);

    expect($this->projection->billedInstallments($card, Carbon::parse('2026-10-15')))->toBe(0)
        ->and($this->projection->totalRemaining($card, Carbon::parse('2026-10-15')))->toBe(950_000);
});

test('day 31 is clamped to the last day of a short month', function (): void {
    $card = cardFixture(['statement_day' => 31], ['due_day' => 15]);

    $statement = $this->projection->nextStatement($card, Carbon::parse('2027-02-01'));

    expect($statement['closes_on']->toDateString())->toBe('2027-02-28')
        ->and($statement['due_on']->toDateString())->toBe('2027-03-15');
});

test('utilization counts the revolving balance and what is still owed on installments', function (): void {
    // 1,000 revolving + 6,000 + 2,500 owed, against a 100,000 limit.
    expect($this->projection->utilization(cardFixture(), now()))->toBe(0.095)
        ->and($this->projection->utilization(cardFixture(['credit_limit' => 0]), now()))->toBeNull()
        ->and($this->projection->utilization(amortizedLoanFixture(), now()))->toBeNull();
});

test('a loan or informal debt is next due on its due day', function (): void {
    $loan = amortizedLoanFixture();
    $loan->update(['due_day' => 20]);

    expect($this->projection->nextDueDate($loan, now())->toDateString())->toBe('2026-10-20');

    $loan->update(['due_day' => 5]);
    expect($this->projection->nextDueDate($loan, now())->toDateString())->toBe('2026-11-05');

    expect($this->projection->nextDueDate(informalFixture(), now()))->toBeNull();
});

test('payments made this month are totalled separately from what is due', function (): void {
    $debt = informalFixture();
    DebtPayment::factory()->for($debt)->create(['date' => '2026-10-03', 'amount' => 40_000]);
    DebtPayment::factory()->for($debt)->create(['date' => '2026-10-12', 'amount' => 10_000, 'is_extra' => true]);
    DebtPayment::factory()->for($debt)->create(['date' => '2026-09-28', 'amount' => 99_000]);

    expect($this->projection->paidInMonth($debt->load('payments'), now()))->toBe(50_000);
});

test('the total monthly debt payment adds up every debt', function (): void {
    $debts = collect([cardFixture(), amortizedLoanFixture(), addOnLoanFixture(), informalFixture()]);

    // 1,500.00 card + 1,066.19 loan + 1,900.00 add-on + 1,250.00 informal.
    expect($this->projection->totalMonthlyPayment($debts, now()))->toBe(524_619);
});

test('the roll-off series drops in the months obligations end', function (): void {
    $debts = collect([cardFixture(), amortizedLoanFixture(), addOnLoanFixture(), informalFixture()]);

    $series = collect($this->projection->rollOff($debts, now()))->pluck('total', 'month');

    expect($series)->toHaveCount(24)
        // Everything running: Oct 2026 to Jan 2027.
        ->and($series['2026-10'])->toBe(571_619)
        ->and($series['2027-01'])->toBe(571_619)
        // The add-on loan and informal debt finish in January.
        ->and($series['2027-02'])->toBe(100_000 + 50_000 + 106_619)
        // The 500 installment ended in February.
        ->and($series['2027-03'])->toBe(100_000 + 106_619)
        // The 1,000 installment ended in March; only the loan is left.
        ->and($series['2027-04'])->toBe(106_619)
        // The loan's last payment is in August 2027, and after that nothing.
        ->and($series['2027-08'])->toBeGreaterThan(100_000)->toBeLessThan(110_000)
        ->and($series['2027-09'])->toBe(0)
        ->and($series['2028-09'])->toBe(0);
});

test('the roll-off series for a single debt only counts that debt', function (): void {
    $series = $this->projection->rollOff(collect([cardFixture()]), now());

    expect($series[0])->toBe(['month' => '2026-10', 'total' => 150_000])
        ->and($series[5])->toBe(['month' => '2027-03', 'total' => 100_000])
        ->and($series[6])->toBe(['month' => '2027-04', 'total' => 0]);
});
