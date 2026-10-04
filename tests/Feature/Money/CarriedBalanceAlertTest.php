<?php

use App\Models\Money\CardBalanceSnapshot;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtStatus;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
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
 * A card with an exact balance history. Months are "Y-m"; a month with no entry
 * has no saved balance. The snapshot the card saves for itself is replaced.
 *
 * @param  array<string, int>  $history
 */
function cardWithHistory(User $user, array $history, string $name = 'Platinum'): Debt
{
    $debt = Debt::factory()->for($user)->create([
        'name' => $name,
        'type' => DebtType::CreditCard,
        'interest_type' => InterestType::Revolving,
        'monthly_interest_rate' => 3.5,
    ]);
    CardDetail::factory()->for($debt)->create(['revolving_balance' => end($history) ?: 0]);
    CardBalanceSnapshot::where('debt_id', $debt->id)->delete();

    foreach ($history as $month => $balance) {
        CardBalanceSnapshot::create(['debt_id' => $debt->id, 'month' => $month, 'revolving_balance' => $balance]);
    }

    return $debt;
}

function carriedAlerts(User $user): array
{
    return app(Projection::class)->carriedBalanceAlerts(
        $user->debts()->with(['cardDetail', 'balanceSnapshots'])->get(),
        Carbon::today(),
    );
}

test('saving a card keeps its balance for the month', function (): void {
    $card = cardWithHistory($this->user, ['2026-10' => 50_000]);

    $card->cardDetail->update(['revolving_balance' => 30_000]);

    expect(CardBalanceSnapshot::where('debt_id', $card->id)->pluck('revolving_balance', 'month')->all())->toBe(['2026-10' => 30_000]);

    $this->travelTo(Carbon::parse('2026-11-03'));
    $card->cardDetail->update(['revolving_balance' => 10_000]);

    expect(CardBalanceSnapshot::where('debt_id', $card->id)->orderBy('month')->pluck('revolving_balance', 'month')->all())->toBe(['2026-10' => 30_000, '2026-11' => 10_000]);
});

test('saving a card without changing the balance keeps no new snapshot', function (): void {
    $card = cardWithHistory($this->user, ['2026-09' => 50_000]);
    $card->cardDetail->update(['revolving_balance' => 50_000]);

    $card->cardDetail->update(['statement_day' => 12]);

    expect(CardBalanceSnapshot::where('debt_id', $card->id)->pluck('month')->all())->toBe(['2026-09']);
});

test('adding a card through the form records its first snapshot', function (): void {
    $this->actingAs($this->user)->post('/money/debts', [
        'name' => 'New card', 'type' => 'credit_card', 'interest_type' => 'revolving', 'monthly_interest_rate' => 3.5,
        'due_day' => 28, 'credit_limit' => 50000, 'statement_day' => 10, 'revolving_balance' => 1234.56,
    ])->assertRedirect();

    expect(CardBalanceSnapshot::sole()->only(['month', 'revolving_balance']))->toBe(['month' => '2026-10', 'revolving_balance' => 123_456]);
});

test('a balance carried in all three months is flagged', function (): void {
    cardWithHistory($this->user, ['2026-08' => 50_000, '2026-09' => 40_000, '2026-10' => 30_000]);

    expect(carriedAlerts($this->user))->toBe([
        ['debtId' => $this->user->debts()->first()->id, 'name' => 'Platinum', 'monthsCarried' => 3, 'balance' => 30_000],
    ]);
});

test('two of the last three months is enough', function (): void {
    cardWithHistory($this->user, ['2026-08' => 0, '2026-09' => 30_000, '2026-10' => 20_000]);

    expect(carriedAlerts($this->user)[0]['monthsCarried'])->toBe(2);
});

test('one month is not a pattern', function (): void {
    cardWithHistory($this->user, ['2026-08' => 0, '2026-09' => 0, '2026-10' => 10_000]);

    expect(carriedAlerts($this->user))->toBe([]);
});

test('a card that is clear now is not flagged', function (): void {
    cardWithHistory($this->user, ['2026-08' => 50_000, '2026-09' => 50_000, '2026-10' => 0]);

    expect(carriedAlerts($this->user))->toBe([]);
});

test('a balance that was never updated counts for every month since it was saved', function (): void {
    cardWithHistory($this->user, ['2026-08' => 50_000]);

    expect(carriedAlerts($this->user)[0])->toMatchArray(['monthsCarried' => 3, 'balance' => 50_000]);
});

test('there is no alert until a balance has been kept for three months', function (): void {
    cardWithHistory($this->user, ['2026-09' => 50_000, '2026-10' => 50_000]);

    expect(carriedAlerts($this->user))->toBe([]);
});

test('paid off cards and loans are never flagged', function (): void {
    cardWithHistory($this->user, ['2026-08' => 50_000], 'Closed')->update(['status' => DebtStatus::PaidOff]);
    Debt::factory()->for($this->user)->create();

    expect(carriedAlerts($this->user))->toBe([]);
});

test('the overview lists alerts for my cards only', function (): void {
    cardWithHistory($this->user, ['2026-08' => 50_000]);
    cardWithHistory(User::factory()->create(), ['2026-08' => 90_000], 'Someone else');

    $this->actingAs($this->user)->get('/money')->assertInertia(fn ($page) => $page
        ->has('carryAlerts', 1)
        ->where('carryAlerts.0.name', 'Platinum')
        ->where('carryAlerts.0.monthsCarried', 3));
});
