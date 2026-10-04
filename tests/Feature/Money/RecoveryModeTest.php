<?php

use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtStatus;
use App\Models\Money\DebtType;
use App\Models\Money\IncomeSource;
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

/** A card whose minimum is 5% of the balance, with no floor. */
function recoveryCard(User $user, string $name, float $rate, int $revolving, array $card = []): Debt
{
    $debt = Debt::factory()->for($user)->create([
        'name' => $name,
        'type' => DebtType::CreditCard,
        'interest_type' => InterestType::Revolving,
        'monthly_interest_rate' => $rate,
        'due_day' => 28,
    ]);
    CardDetail::factory()->for($debt)->create(['statement_day' => 10, 'min_payment_percent' => 5, 'min_payment_floor' => 0, 'revolving_balance' => $revolving, ...$card]);

    return $debt;
}

function recoveryPlan(User $user, int $months): array
{
    return app(Projection::class)->recoveryPlan($user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get(), $months, Carbon::today());
}

test('a card without interest needs an even share each month beyond its minimum', function (): void {
    recoveryCard($this->user, 'Platinum', 0, 300_000);

    // 3,000.00 over 3 months is 1,000.00 a month. The minimum already pays 150.00 of it.
    expect(recoveryPlan($this->user, 3))->toBe([
        'months' => 3,
        'carryover' => 300_000,
        'extra' => 85_000,
        'cards' => [['debtId' => $this->user->debts()->first()->id, 'name' => 'Platinum', 'balance' => 300_000, 'payment' => 100_000, 'extra' => 85_000]],
    ]);
});

test('the payment includes interest and really clears the card in time', function (): void {
    $card = recoveryCard($this->user, 'Platinum', 2.0, 100_000);

    $plan = recoveryPlan($this->user, 3);
    $schedule = app(Projection::class)->clearingSchedule($card->load(['cardDetail', 'paymentPlans.debt', 'payments']), $plan['cards'][0]['payment'], Carbon::today());

    // Minimum is 5% of 1,000.00 = 50.00, so 346.75 - 50.00 is extra.
    expect($plan['cards'][0])->toMatchArray(['payment' => 34_675, 'extra' => 29_675])
        ->and($schedule['months'])->toBe(3);
});

test('nothing extra is needed when the minimum already pays enough', function (): void {
    recoveryCard($this->user, 'Platinum', 2.0, 100_000, ['min_payment_floor' => 50_000]);

    expect(recoveryPlan($this->user, 3)['extra'])->toBe(0);
});

test('extra is added up across cards and clearing cards, loans and cards with nothing carried are skipped', function (): void {
    recoveryCard($this->user, 'A', 0, 300_000);
    recoveryCard($this->user, 'B', 0, 600_000);
    recoveryCard($this->user, 'Empty', 3.0, 0);
    recoveryCard($this->user, 'Closed', 3.0, 500_000)->update(['status' => DebtStatus::PaidOff]);
    Debt::factory()->for($this->user)->create();

    $plan = recoveryPlan($this->user, 3);

    expect(array_column($plan['cards'], 'name'))->toBe(['A', 'B'])
        ->and($plan['carryover'])->toBe(900_000)
        ->and($plan['extra'])->toBe(85_000 + 170_000);
});

test('recovery mode takes the extra off safe to spend', function (): void {
    IncomeSource::factory()->for($this->user)->create(['amount' => 1_000_000]);
    recoveryCard($this->user, 'Platinum', 0, 300_000);
    $debts = $this->user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();
    $sources = $this->user->incomeSources()->get();
    $projection = app(Projection::class);

    $off = $projection->overview($debts, collect(), $sources, Carbon::today());
    $on = $projection->overview($debts, collect(), $sources, Carbon::today(), 0, 3);

    // 10,000.00 income less the 150.00 minimum, then 850.00 more when recovering.
    expect($off)->toMatchArray(['recoveryContribution' => 0, 'safeToSpend' => 985_000])
        ->and($on)->toMatchArray(['recoveryContribution' => 85_000, 'safeToSpend' => 900_000]);
});

test('the overview offers recovery mode only while a card carries a balance', function (): void {
    $this->actingAs($this->user)->get('/money')->assertInertia(fn ($page) => $page->where('recovery', null));

    recoveryCard($this->user, 'Platinum', 0, 300_000);

    $this->actingAs($this->user)->get('/money')->assertInertia(fn ($page) => $page
        ->where('recovery.carryover', 300_000)
        ->where('recovery.months', null)
        ->where('recovery.extra', 0)
        ->has('recovery.options', 4)
        ->where('recovery.options.0', ['months' => 3, 'extra' => 85_000]));
});

test('turning recovery mode on lowers safe to spend on the overview and today', function (): void {
    IncomeSource::factory()->for($this->user)->create(['amount' => 1_000_000]);
    recoveryCard($this->user, 'Platinum', 0, 300_000);

    $this->actingAs($this->user)->patch('/money/recovery', ['months' => 3])->assertRedirect();

    $user = $this->user->fresh();
    expect($user->setting->recovery_months)->toBe(3);
    $this->actingAs($user)->get('/money')->assertInertia(fn ($page) => $page
        ->where('recovery.months', 3)
        ->where('recovery.extra', 85_000)
        ->where('numbers.recoveryContribution', 85_000)
        ->where('numbers.safeToSpend', 900_000));
    $this->actingAs($user->fresh())->get('/today')->assertInertia(fn ($page) => $page->where('moneySummary.safeToSpend', 900_000));
});

test('recovery mode can be turned off again', function (): void {
    $this->user->setting()->create(['theme' => 'ocean', 'recovery_months' => 6]);

    $this->actingAs($this->user)->patch('/money/recovery', ['months' => null])->assertRedirect();

    expect($this->user->fresh()->setting->recovery_months)->toBeNull();
});

test('recovery months must be one of the offered choices', function (): void {
    $this->actingAs($this->user)->patch('/money/recovery', ['months' => 4])->assertSessionHasErrors('months');
    $this->actingAs($this->user)->patch('/money/recovery', [])->assertSessionHasErrors('months');
    expect($this->user->fresh()->setting)->toBeNull();
});

test('guests cannot change recovery mode', function (): void {
    $this->patch('/money/recovery', ['months' => 3])->assertRedirect('/login');
});
