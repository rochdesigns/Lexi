<?php

use App\Models\Money\Account;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
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

function chargeCard(User $user, int $revolving = 100_000, float $rate = 3.5): Debt
{
    $card = Debt::factory()->for($user)->create([
        'name' => 'Platinum',
        'type' => DebtType::CreditCard,
        'interest_type' => InterestType::Revolving,
        'monthly_interest_rate' => $rate,
        'due_day' => 28,
    ]);
    CardDetail::factory()->for($card)->create(['statement_day' => 10, 'revolving_balance' => $revolving]);

    return $card;
}

function charge(User $user, Debt $card, string $date, int $amount): Transaction
{
    return Transaction::factory()->for($user)->onCard($card)->create(['date' => $date, 'amount' => $amount]);
}

function projected(Debt $card, string $today = '2026-10-15'): int
{
    return app(Projection::class)->projectedNextStatement($card->fresh(['cardDetail', 'paymentPlans.debt', 'transactions']), Carbon::parse($today));
}

test('charges since the last statement closed join the projected statement', function (): void {
    $card = chargeCard($this->user);

    // The statement closed on Oct 10 and the next closes on Nov 10.
    charge($this->user, $card, '2026-10-09', 20_000);
    charge($this->user, $card, '2026-10-10', 30_000);
    charge($this->user, $card, '2026-10-11', 40_000);
    charge($this->user, $card, '2026-10-14', 50_000);
    charge($this->user, $card, '2026-11-10', 60_000);
    charge($this->user, $card, '2026-11-11', 70_000);

    expect(projected($card))->toBe(100_000 + 40_000 + 50_000 + 60_000);
});

test('on the closing day the charges of the closing statement count', function (): void {
    $card = chargeCard($this->user);
    charge($this->user, $card, '2026-09-10', 20_000);
    charge($this->user, $card, '2026-09-11', 30_000);
    charge($this->user, $card, '2026-10-10', 40_000);

    expect(projected($card, '2026-10-10'))->toBe(100_000 + 30_000 + 40_000);
});

test('only charges to this card count, not account expenses or another card', function (): void {
    $card = chargeCard($this->user);
    $other = chargeCard($this->user);
    charge($this->user, $other, '2026-10-12', 99_000);
    Transaction::factory()->for($this->user)->create(['account_id' => Account::factory()->for($this->user), 'date' => '2026-10-12', 'amount' => 88_000]);

    expect(projected($card))->toBe(100_000);
});

test('a card with no charges projects what it did before', function (): void {
    expect(projected(chargeCard($this->user)))->toBe(100_000);
});

test('the debts page shows the projected statement with the charges', function (): void {
    $card = chargeCard($this->user);
    charge($this->user, $card, '2026-10-12', 25_000);

    $this->actingAs($this->user)->get('/money/debts')->assertInertia(fn ($page) => $page->where('debts.0.card.projectedStatement', 125_000));
    $this->actingAs($this->user)->get("/money/debts/{$card->id}")->assertInertia(fn ($page) => $page->where('debt.card.projectedStatement', 125_000));
});

test('the guard warns about a card carrying a balance and suggests the richest spendable account', function (): void {
    $card = chargeCard($this->user, 250_000, 3.5);
    Account::factory()->for($this->user)->create(['name' => 'Small', 'balance' => 100_000]);
    Account::factory()->for($this->user)->create(['name' => 'Wallet', 'balance' => 800_000]);
    Account::factory()->for($this->user)->notSpendable()->create(['name' => 'Savings', 'balance' => 9_000_000]);

    $guard = app(Projection::class)->spendingGuard($card->fresh('cardDetail'), $this->user->accounts()->get());

    expect($guard)->toMatchArray(['debtId' => $card->id, 'name' => 'Platinum', 'revolving' => 250_000, 'monthlyRate' => 3.5])
        ->and($guard['suggestedAccount'])->toMatchArray(['name' => 'Wallet', 'balance' => 800_000]);
});

test('the guard has no suggestion when no account has money to spend', function (): void {
    $card = chargeCard($this->user, 250_000);
    Account::factory()->for($this->user)->notSpendable()->create(['balance' => 9_000_000]);
    Account::factory()->for($this->user)->create(['balance' => 0]);

    expect(app(Projection::class)->spendingGuard($card->fresh('cardDetail'), $this->user->accounts()->get())['suggestedAccount'])->toBeNull();
});

test('the guard stays quiet for a card with nothing carried over', function (): void {
    $card = chargeCard($this->user, 0);

    expect(app(Projection::class)->spendingGuard($card->fresh('cardDetail'), collect()))->toBeNull();
});

test('the transactions page hands each card its guard', function (): void {
    chargeCard($this->user, 250_000);
    Account::factory()->for($this->user)->create(['name' => 'Wallet', 'balance' => 800_000]);

    $this->actingAs($this->user)->get('/money/transactions')->assertInertia(fn ($page) => $page
        ->where('cards.0.guard.revolving', 250_000)
        ->where('cards.0.guard.suggestedAccount.name', 'Wallet'));
});
