<?php

use App\Models\Money\Bill;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtStatus;
use App\Models\Money\DebtType;
use App\Models\Money\Goal;
use App\Models\Money\IncomeSource;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Models\Money\Transaction;
use App\Models\User;
use App\Services\Money\Projection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

/**
 * Income 50,000.00, bills 4,000.00 and a loan billing 5,000.00 in October and
 * November: safe to spend is 41,000.00 in those two months and 46,000.00 after.
 */
function seedMonths(User $user): void
{
    IncomeSource::factory()->for($user)->create(['amount' => 5_000_000]);
    Bill::factory()->for($user)->create(['amount' => 150_000, 'due_day' => 25]);
    Bill::factory()->for($user)->create(['amount' => 250_000, 'due_day' => 5]);
    $loan = Debt::factory()->for($user)->create(['name' => 'Bank loan', 'due_day' => 20]);
    PaymentPlan::factory()->for($loan)->create(['principal' => 1_000_000, 'monthly_amount' => 500_000, 'total_months' => 2, 'start_month' => '2026-10-01']);
}

function whatIfCard(User $user, float $rate = 3.5, int $revolving = 100_000): Debt
{
    $card = Debt::factory()->for($user)->create(['name' => 'Platinum', 'type' => DebtType::CreditCard, 'interest_type' => InterestType::Revolving, 'monthly_interest_rate' => $rate, 'due_day' => 28]);
    CardDetail::factory()->for($card)->create(['statement_day' => 10, 'revolving_balance' => $revolving, 'min_payment_percent' => 5, 'min_payment_floor' => 0]);

    return $card;
}

function afford(array $payload)
{
    return test()->actingAs(test()->user)->postJson('/money/plan/what-if/afford', $payload);
}

test('safe to spend by month matches the overview for this month and follows the loan rolling off', function (): void {
    seedMonths($this->user);
    $debts = $this->user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();
    $projection = new Projection;

    $series = $projection->safeToSpendByMonth($debts, $this->user->bills()->get(), $this->user->incomeSources()->get(), Carbon::today(), ['buffer' => 0, 'sinking' => 0], null);
    $overview = $projection->overview($debts, $this->user->bills()->get(), $this->user->incomeSources()->get(), Carbon::today());

    expect($series)->toHaveCount(12)
        ->and($series[0])->toBe(['month' => '2026-10', 'safeToSpend' => 4_100_000])
        ->and($series[0]['safeToSpend'])->toBe($overview['safeToSpend'])
        ->and($series[1]['safeToSpend'])->toBe(4_100_000)
        ->and($series[2])->toBe(['month' => '2026-12', 'safeToSpend' => 4_600_000])
        ->and($series[11]['month'])->toBe('2027-09');
});

test('goal contributions and recovery come off every month', function (): void {
    seedMonths($this->user);
    $debts = $this->user->debts()->get();

    $series = (new Projection)->safeToSpendByMonth($debts, $this->user->bills()->get(), $this->user->incomeSources()->get(), Carbon::today(), ['buffer' => 200_000, 'sinking' => 100_000], null);

    expect($series[0]['safeToSpend'])->toBe(3_800_000)->and($series[2]['safeToSpend'])->toBe(4_300_000);
});

test('a card installment starts with the next statement and flags every month that goes short', function (): void {
    seedMonths($this->user);
    $card = whatIfCard($this->user);

    // The next statement closes Nov 10 and is due Nov 28. 360,000.00 over 6 months is 60,000.00 a month.
    // This month also pays the card's own 50.00 minimum (5% of 1,000.00), which later months do not know about.
    $response = afford(['price' => 360000, 'months' => 6, 'debt_id' => $card->id])->assertOk();

    $response->assertJsonPath('monthlyAmount', 6_000_000)
        ->assertJsonPath('firstMonth', '2026-11')
        ->assertJsonPath('lastMonth', '2027-04')
        ->assertJsonPath('rows.0', ['month' => '2026-10', 'before' => 4_095_000, 'after' => 4_095_000, 'short' => false, 'newlyShort' => false])
        ->assertJsonPath('rows.1', ['month' => '2026-11', 'before' => 4_100_000, 'after' => -1_900_000, 'short' => true, 'newlyShort' => true])
        ->assertJsonPath('rows.2.after', -1_400_000)
        ->assertJsonPath('rows.7.after', 4_600_000)
        ->assertJsonPath('shortMonths', ['2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04']);
});

test('an installment that fits flags nothing', function (): void {
    seedMonths($this->user);
    $card = whatIfCard($this->user);

    afford(['price' => 60000, 'months' => 6, 'debt_id' => $card->id])->assertOk()
        ->assertJsonPath('shortMonths', [])
        ->assertJsonPath('rows.1.after', 3_100_000)
        ->assertJsonPath('rows.2.after', 3_600_000);
});

test('without a card the installment starts in the month given', function (): void {
    seedMonths($this->user);

    afford(['price' => 12000, 'months' => 3, 'start_month' => '2026-12'])->assertOk()
        ->assertJsonPath('firstMonth', '2026-12')
        ->assertJsonPath('rows.1.after', 4_100_000)
        ->assertJsonPath('rows.2.after', 4_200_000)
        ->assertJsonPath('rows.3.after', 4_200_000)
        ->assertJsonPath('rows.4.after', 4_200_000)
        ->assertJsonPath('rows.5.after', 4_600_000);
});

test('the payments add up to the price when it does not divide evenly', function (): void {
    seedMonths($this->user);

    $rows = afford(['price' => 10000, 'months' => 3, 'start_month' => '2026-10'])->assertOk()->json('rows');

    expect(array_map(fn (array $row): int => $row['before'] - $row['after'], array_slice($rows, 0, 3)))->toBe([333_334, 333_334, 333_332]);
});

test('a month that was already short is short but not newly short', function (): void {
    Bill::factory()->for($this->user)->create(['amount' => 100_000, 'due_day' => 25]);

    $row = afford(['price' => 100, 'months' => 1, 'start_month' => '2026-10'])->assertOk()->json('rows.0');

    expect($row)->toMatchArray(['before' => -100_000, 'short' => true, 'newlyShort' => false]);
});

test('only the next twelve months are shown', function (): void {
    seedMonths($this->user);

    $response = afford(['price' => 24000, 'months' => 24, 'start_month' => '2026-10'])->assertOk();

    expect($response->json('rows'))->toHaveCount(12)->and($response->json('lastMonth'))->toBe('2028-09');
});

test('the afford check needs sensible numbers and my own card', function (): void {
    $card = whatIfCard($this->user);
    $theirs = whatIfCard(User::factory()->create());
    $loan = Debt::factory()->for($this->user)->create();

    afford(['price' => 0, 'months' => 6, 'debt_id' => $card->id])->assertJsonValidationErrors('price');
    afford(['price' => 100, 'months' => 0, 'debt_id' => $card->id])->assertJsonValidationErrors('months');
    afford(['price' => 100, 'months' => 61, 'debt_id' => $card->id])->assertJsonValidationErrors('months');
    afford(['price' => 100, 'months' => 6])->assertJsonValidationErrors('start_month');
    afford(['price' => 100, 'months' => 6, 'debt_id' => $theirs->id])->assertJsonValidationErrors('debt_id');
    afford(['price' => 100, 'months' => 6, 'debt_id' => $loan->id])->assertJsonValidationErrors('debt_id');
});

/**
 * Remaining balances: A 10,000.00 (10 months), B 15,000.00 (3 months), C 3,000.00 (6 months).
 */
function seedPlans(User $user): void
{
    $debt = Debt::factory()->for($user)->create(['name' => 'Bank loan']);
    PaymentPlan::factory()->for($debt)->create(['description' => 'A', 'principal' => 1_000_000, 'monthly_amount' => 100_000, 'total_months' => 10, 'start_month' => '2026-10-01']);
    PaymentPlan::factory()->for($debt)->create(['description' => 'B', 'principal' => 1_500_000, 'monthly_amount' => 500_000, 'total_months' => 3, 'start_month' => '2026-10-01']);
    PaymentPlan::factory()->for($debt)->create(['description' => 'C', 'principal' => 300_000, 'monthly_amount' => 50_000, 'total_months' => 6, 'start_month' => '2026-10-01']);
}

function extraCash(int|float $amount)
{
    return test()->actingAs(test()->user)->postJson('/money/plan/what-if/extra-cash', ['amount' => $amount]);
}

test('plans are ranked by how fast they finish and paid off while the money lasts', function (): void {
    seedPlans($this->user);

    $response = extraCash(19000)->assertOk();

    expect(array_column($response->json('plans'), 'description'))->toBe(['B', 'C', 'A'])
        ->and(array_column($response->json('plans'), 'paysOff'))->toBe([true, true, false])
        ->and($response->json())->toMatchArray(['amount' => 1_900_000, 'spent' => 1_800_000, 'leftover' => 100_000, 'freedMonthly' => 550_000]);
});

test('a plan the money cannot cover is skipped and the next one it can cover is paid', function (): void {
    seedPlans($this->user);

    $response = extraCash(10000)->assertOk();

    expect(array_column($response->json('plans'), 'paysOff'))->toBe([false, true, false])
        ->and($response->json())->toMatchArray(['spent' => 300_000, 'leftover' => 700_000, 'freedMonthly' => 50_000]);
});

test('with no spare cash nothing is paid off', function (): void {
    seedPlans($this->user);

    expect(extraCash(0)->assertOk()->json())->toMatchArray(['spent' => 0, 'freedMonthly' => 0])
        ->and(array_unique(array_column(extraCash(0)->json('plans'), 'paysOff')))->toBe([false]);
});

test('add-on plans are marked, and finished plans are left out', function (): void {
    $debt = Debt::factory()->for($this->user)->create(['interest_type' => InterestType::AddOn, 'monthly_interest_rate' => 2.4]);
    PaymentPlan::factory()->for($debt)->create(['description' => 'Maya', 'principal' => 4_200_000, 'monthly_amount' => 800_800, 'total_months' => 6, 'start_month' => '2026-10-01']);
    PaymentPlan::factory()->for($debt)->create(['description' => 'Done', 'status' => DebtStatus::PaidOff, 'start_month' => '2026-10-01']);

    $plans = extraCash(100)->assertOk()->json('plans');

    expect($plans)->toHaveCount(1)->and($plans[0])->toMatchArray(['description' => 'Maya', 'interestType' => 'add_on', 'savesInterest' => false]);
});

test('the extra cash amount cannot be negative', function (): void {
    extraCash(-1)->assertJsonValidationErrors('amount');
});

function convert(array $payload)
{
    return test()->actingAs(test()->user)->postJson('/money/plan/what-if/conversion', $payload);
}

test('a conversion is compared with leaving the balance revolving at the same payment', function (): void {
    $card = whatIfCard($this->user, 3.5, 100_000);

    // 1,000.00 over 4 months at 1% flat: 250.00 + 10.00 = 260.00 a month, 40.00 of interest.
    // Revolving at 3.5% paying 260.00: 5 payments and 53.29 of interest.
    convert(['debt_id' => $card->id, 'months' => 4, 'rate' => 1.0])->assertOk()->assertJson([
        'balance' => 100_000,
        'monthlyPayment' => 26_000,
        'conversion' => ['months' => 4, 'totalInterest' => 4_000, 'totalPaid' => 104_000],
        'revolving' => ['months' => 5, 'totalInterest' => 5_329, 'totalPaid' => 105_329, 'neverClears' => false],
        'cheaper' => 'conversion',
        'interestSaved' => 1_329,
    ]);
});

test('revolving can be the cheaper way when the card rate is low', function (): void {
    $card = whatIfCard($this->user, 0.5, 100_000);

    convert(['debt_id' => $card->id, 'months' => 4, 'rate' => 2.0])->assertOk()
        ->assertJsonPath('cheaper', 'revolving')
        ->assertJsonPath('conversion.totalInterest', 8_000);
});

test('when the same payment never clears the revolving balance the conversion wins', function (): void {
    $card = whatIfCard($this->user, 50.0, 100_000);

    convert(['debt_id' => $card->id, 'months' => 4, 'rate' => 1.0])->assertOk()
        ->assertJsonPath('revolving.neverClears', true)
        ->assertJsonPath('cheaper', 'conversion')
        ->assertJsonPath('interestSaved', 0);
});

test('the conversion needs a card that carries a balance, sensible numbers and my own card', function (): void {
    $clear = whatIfCard($this->user, 3.5, 0);
    $card = whatIfCard($this->user);
    $theirs = whatIfCard(User::factory()->create());

    convert(['debt_id' => $clear->id, 'months' => 4, 'rate' => 1])->assertStatus(422);
    convert(['debt_id' => $theirs->id, 'months' => 4, 'rate' => 1])->assertJsonValidationErrors('debt_id');
    convert(['debt_id' => $card->id, 'months' => 1, 'rate' => 1])->assertJsonValidationErrors('months');
    convert(['debt_id' => $card->id, 'months' => 4, 'rate' => 21])->assertJsonValidationErrors('rate');
});

test('simulations never write anything', function (): void {
    seedMonths($this->user);
    seedPlans($this->user);
    $card = whatIfCard($this->user);
    Goal::factory()->for($this->user)->create();
    $counts = fn (): array => [Debt::count(), PaymentPlan::count(), Transaction::count(), Bill::count(), Goal::count(), CardDetail::count()];
    $before = $counts();
    $balance = $card->cardDetail->fresh()->revolving_balance;

    afford(['price' => 360000, 'months' => 6, 'debt_id' => $card->id])->assertOk();
    extraCash(19000)->assertOk();
    convert(['debt_id' => $card->id, 'months' => 4, 'rate' => 1.0])->assertOk();

    expect($counts())->toBe($before)->and($card->cardDetail->fresh()->revolving_balance)->toBe($balance);
});

test('guests cannot run simulations', function (): void {
    $this->postJson('/money/plan/what-if/afford', [])->assertUnauthorized();
    $this->postJson('/money/plan/what-if/extra-cash', [])->assertUnauthorized();
    $this->postJson('/money/plan/what-if/conversion', [])->assertUnauthorized();
});

test('the plan page hands the simulator my cards', function (): void {
    whatIfCard($this->user, 3.5, 250_000);
    whatIfCard(User::factory()->create());

    $this->actingAs($this->user)->get('/money/plan')->assertInertia(fn ($page) => $page
        ->has('cards', 1)
        ->where('cards.0.name', 'Platinum')
        ->where('cards.0.revolvingBalance', 250_000)
        ->where('hasPlans', false));
});
