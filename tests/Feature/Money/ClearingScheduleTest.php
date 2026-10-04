<?php

use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
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

function revolvingCard(User $user, float $rate, int $revolving, array $card = []): Debt
{
    $debt = Debt::factory()->for($user)->create([
        'name' => 'Platinum',
        'type' => DebtType::CreditCard,
        'interest_type' => InterestType::Revolving,
        'monthly_interest_rate' => $rate,
        'due_day' => 28,
    ]);
    CardDetail::factory()->for($debt)->create(['statement_day' => 10, 'revolving_balance' => $revolving, ...$card]);

    return $debt->load(['cardDetail', 'paymentPlans.debt', 'payments']);
}

function clearing(Debt $debt, int $payment): array
{
    return app(Projection::class)->clearingSchedule($debt, $payment, Carbon::today());
}

test('a balance clears month by month with interest on what is left', function (): void {
    // 1,000.00 at 2% paying 300.00: 700 + 14 = 714, 414 + 8.28 = 422.28, 122.28 + 2.45 = 124.73, then 124.73.
    $result = clearing(revolvingCard($this->user, 2.0, 100_000), 30_000);

    expect($result)->toBe([
        'months' => 4,
        'clearedBy' => '2027-01',
        'totalPaid' => 102_473,
        'totalInterest' => 2_473,
        'neverClears' => false,
    ]);
});

test('a card with no interest clears in even payments', function (): void {
    $result = clearing(revolvingCard($this->user, 0, 100_000), 25_000);

    expect($result)->toMatchArray(['months' => 4, 'totalInterest' => 0, 'totalPaid' => 100_000, 'neverClears' => false]);
});

test('a payment covering the whole balance clears it in one month', function (): void {
    $result = clearing(revolvingCard($this->user, 3.5, 100_000), 500_000);

    expect($result)->toMatchArray(['months' => 1, 'clearedBy' => '2026-10', 'totalPaid' => 100_000, 'totalInterest' => 0]);
});

test('a payment that does not outrun the interest never clears', function (): void {
    // 5% of the 980.00 left after paying 20.00 is 49.00, more than was paid.
    $card = revolvingCard($this->user, 5.0, 100_000);

    expect(clearing($card, 2_000))->toMatchArray(['neverClears' => true, 'months' => 0, 'clearedBy' => null])
        ->and(clearing($card, 0)['neverClears'])->toBeTrue();
});

test('a card with nothing revolving has nothing to clear', function (): void {
    expect(clearing(revolvingCard($this->user, 3.5, 0), 10_000))->toMatchArray(['months' => 0, 'clearedBy' => null, 'neverClears' => false]);
});

test('only the part of the minimum that pays the balance is suggested', function (): void {
    $card = revolvingCard($this->user, 2.0, 1_000_000, ['min_payment_percent' => 5, 'min_payment_floor' => 0, 'charges_due_in_full' => 20_000]);
    PaymentPlan::factory()->for($card)->create(['principal' => 1_200_000, 'monthly_amount' => 100_000, 'total_months' => 12, 'start_month' => '2026-01-01', 'in_minimum' => true]);
    $card->load('paymentPlans');

    // Minimum: 5% of (10,000 - 200) = 490, plus 200 charges, plus 1,000 installment = 1,690. 490 pays the balance.
    expect(app(Projection::class)->minimumDue($card, Carbon::today()))->toBe(169_000)
        ->and(app(Projection::class)->minimumTowardsBalance($card, Carbon::today()))->toBe(49_000);
});

test('the debt page suggests a payment for a card and nothing for a loan', function (): void {
    $card = revolvingCard($this->user, 2.0, 1_000_000, ['min_payment_percent' => 5, 'min_payment_floor' => 0]);
    $loan = Debt::factory()->for($this->user)->create();

    $this->actingAs($this->user)->get("/money/debts/{$card->id}")->assertInertia(fn ($page) => $page->where('clearingDefault', 50_000));
    $this->actingAs($this->user)->get("/money/debts/{$loan->id}")->assertInertia(fn ($page) => $page->where('clearingDefault', null));
});

test('the clearing endpoint takes pesos', function (): void {
    $card = revolvingCard($this->user, 2.0, 100_000);

    $this->actingAs($this->user)
        ->postJson("/money/debts/{$card->id}/clearing", ['amount' => 300])
        ->assertOk()
        ->assertJsonPath('months', 4)
        ->assertJsonPath('clearedBy', '2027-01')
        ->assertJsonPath('totalInterest', 2_473);
});

test('the clearing endpoint guards other people\'s cards, loans, bad amounts and guests', function (): void {
    $card = revolvingCard($this->user, 2.0, 100_000);
    $loan = Debt::factory()->for($this->user)->create();
    $other = revolvingCard(User::factory()->create(), 2.0, 100_000);

    $this->actingAs($this->user)->postJson("/money/debts/{$other->id}/clearing", ['amount' => 100])->assertForbidden();
    $this->actingAs($this->user)->postJson("/money/debts/{$loan->id}/clearing", ['amount' => 100])->assertNotFound();
    $this->actingAs($this->user)->postJson("/money/debts/{$card->id}/clearing", ['amount' => -5])->assertUnprocessable();
    auth()->logout();
    $this->postJson("/money/debts/{$card->id}/clearing", ['amount' => 100])->assertUnauthorized();
});
