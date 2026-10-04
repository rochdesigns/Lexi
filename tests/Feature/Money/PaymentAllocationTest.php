<?php

use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtStatus;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Models\User;
use App\Services\Money\Projection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

function planCard(User $user, string $name, float $rate, int $revolving, array $card = []): Debt
{
    $debt = Debt::factory()->for($user)->create([
        'name' => $name,
        'type' => DebtType::CreditCard,
        'interest_type' => InterestType::Revolving,
        'monthly_interest_rate' => $rate,
        'due_day' => 28,
    ]);
    CardDetail::factory()->for($debt)->create([
        'statement_day' => 10,
        'min_payment_percent' => 5,
        'min_payment_floor' => 50_000,
        'revolving_balance' => $revolving,
        ...$card,
    ]);

    return $debt;
}

/**
 * High: 3.5% a month on 20,000.00, minimum 1,000.00 (5%).
 * Low: 1.5% a month on 10,000.00, minimum 500.00 (the floor).
 */
function seedTwoCards(User $user): void
{
    planCard($user, 'High', 3.5, 2_000_000);
    planCard($user, 'Low', 1.5, 1_000_000);
}

function allocate(User $user, int $available): array
{
    return app(Projection::class)->allocatePayment(
        $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get(),
        $available,
        Carbon::today(),
    );
}

test('paying only the minimums leaves interest on what is still revolving', function (): void {
    seedTwoCards($this->user);

    $plan = allocate($this->user, 150_000);

    expect($plan)->toMatchArray(['totalMinimums' => 150_000, 'shortBy' => 0, 'leftover' => 0, 'totalInterest' => 80_750])
        ->and($plan['cards'][0])->toMatchArray(['name' => 'High', 'minimumPaid' => 100_000, 'extra' => 0, 'revolvingAfter' => 1_900_000, 'interest' => 66_500])
        ->and($plan['cards'][1])->toMatchArray(['name' => 'Low', 'minimumPaid' => 50_000, 'extra' => 0, 'revolvingAfter' => 950_000, 'interest' => 14_250]);
});

test('extra money goes to the highest rate card first', function (): void {
    seedTwoCards($this->user);

    $plan = allocate($this->user, 400_000);

    expect($plan['cards'][0])->toMatchArray(['name' => 'High', 'extra' => 250_000, 'paid' => 350_000, 'revolvingAfter' => 1_650_000, 'interest' => 57_750])
        ->and($plan['cards'][1])->toMatchArray(['name' => 'Low', 'extra' => 0, 'paid' => 50_000])
        ->and($plan['totalInterest'])->toBe(72_000);
});

test('extra money spills to the next card once the first is cleared', function (): void {
    seedTwoCards($this->user);

    $plan = allocate($this->user, 2_500_000);

    expect($plan['cards'][0])->toMatchArray(['extra' => 1_900_000, 'revolvingAfter' => 0, 'interest' => 0])
        ->and($plan['cards'][1])->toMatchArray(['extra' => 450_000, 'revolvingAfter' => 500_000, 'interest' => 7_500])
        ->and($plan['leftover'])->toBe(0);
});

test('money beyond every revolving balance is left over', function (): void {
    seedTwoCards($this->user);

    $plan = allocate($this->user, 10_000_000);

    expect($plan['leftover'])->toBe(7_000_000)->and($plan['totalInterest'])->toBe(0);
});

test('when the money runs out the lowest rate card misses its minimum', function (): void {
    seedTwoCards($this->user);

    $plan = allocate($this->user, 120_000);

    expect($plan['shortBy'])->toBe(30_000)
        ->and($plan['cards'][0])->toMatchArray(['name' => 'High', 'minimumPaid' => 100_000, 'missedMinimum' => 0])
        ->and($plan['cards'][1])->toMatchArray(['name' => 'Low', 'minimumPaid' => 20_000, 'missedMinimum' => 30_000, 'revolvingAfter' => 980_000, 'interest' => 14_700]);
});

test('installments in the minimum do not reduce the revolving balance', function (): void {
    $card = planCard($this->user, 'Installments', 2.0, 1_000_000, ['min_payment_floor' => 0]);
    PaymentPlan::factory()->for($card)->create(['principal' => 1_200_000, 'monthly_amount' => 100_000, 'total_months' => 12, 'start_month' => '2026-01-01', 'in_minimum' => true]);

    $plan = allocate($this->user, 150_000);

    expect($plan['totalMinimums'])->toBe(150_000)
        ->and($plan['cards'][0])->toMatchArray(['minimumPaid' => 150_000, 'revolvingAfter' => 950_000, 'interest' => 19_000]);
});

test('a card with nothing owing, a paid off card and a loan are left out', function (): void {
    seedTwoCards($this->user);
    planCard($this->user, 'Empty', 3.0, 0, ['min_payment_floor' => 0]);
    planCard($this->user, 'Closed', 3.0, 500_000)->update(['status' => DebtStatus::PaidOff]);
    Debt::factory()->for($this->user)->create(['name' => 'Loan']);

    expect(array_column(allocate($this->user, 150_000)['cards'], 'name'))->toBe(['High', 'Low']);
});

test('negative money is treated as nothing and no cards gives an empty plan', function (): void {
    expect(allocate($this->user, -500))->toMatchArray(['available' => 0, 'totalMinimums' => 0, 'cards' => [], 'leftover' => 0]);
});

test('the allocate endpoint takes pesos and only looks at my cards', function (): void {
    seedTwoCards($this->user);
    planCard(User::factory()->create(), 'Someone else', 5.0, 9_000_000);

    $this->actingAs($this->user)
        ->postJson('/money/debts/allocate', ['amount' => 4000])
        ->assertOk()
        ->assertJsonCount(2, 'cards')
        ->assertJsonPath('available', 400_000)
        ->assertJsonPath('totalInterest', 72_000);
});

test('the allocate endpoint rejects bad amounts and guests', function (): void {
    $this->actingAs($this->user)->postJson('/money/debts/allocate', ['amount' => -1])->assertUnprocessable();
    $this->actingAs($this->user)->postJson('/money/debts/allocate', ['amount' => 'lots'])->assertUnprocessable();
    auth()->logout();
    $this->postJson('/money/debts/allocate', ['amount' => 100])->assertUnauthorized();
});
