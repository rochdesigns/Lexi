<?php

use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Settings;
use App\Models\User;

test('guests are redirected to login', function () {
    $this->get('/payoff-plan')->assertRedirect('/login');
});

test('renders empty state props for a user with no debts', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['debts'])->toBe([]);
    expect($props['monthsRemaining'])->toBeNull();
    expect($props['onTrack'])->toBeTrue();
    expect($props['debtFreeDate'])->toBeNull();
});

test('creates default settings for a first-time visitor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['settings'])->toBe(['strategy' => 'avalanche', 'extra_monthly' => 0.0]);
    expect(Settings::where('user_id', $user->id)->exists())->toBeTrue();
});

test('renders each debt merged with its live payoff simulation result', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create([
        'balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null, 'debt_type' => 'installment',
    ]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['debts'])->toHaveCount(1);
    expect($props['debts'][0]['id'])->toBe($debt->id);
    expect($props['debts'][0]['payoff_month'])->toBe(12);
    expect($props['debts'][0]['total_interest'])->toBeNull();
    expect($props['monthsRemaining'])->toBe(12);
    expect($props['onTrack'])->toBeTrue();
    expect($props['debtFreeDate'])->not->toBeNull();
});

test('orders debts by highest monthly rate first under the avalanche strategy', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $lowRate = Debt::factory()->for($user)->create(['name' => 'Low rate', 'monthly_rate' => 5]);
    $highRate = Debt::factory()->for($user)->create(['name' => 'High rate', 'monthly_rate' => 20]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $names = collect($response->viewData('page')['props']['debts'])->pluck('name');

    expect($names->all())->toBe(['High rate', 'Low rate']);
});

test('orders debts by lowest starting balance first under the snowball strategy', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'snowball']);
    $bigBalance = Debt::factory()->for($user)->create(['name' => 'Big balance', 'balance' => 5000]);
    $smallBalance = Debt::factory()->for($user)->create(['name' => 'Small balance', 'balance' => 500]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $names = collect($response->viewData('page')['props']['debts'])->pluck('name');

    expect($names->all())->toBe(['Small balance', 'Big balance']);
});

test('stores a new debt for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Visa',
        'balance' => 1500,
        'monthly_rate' => 1.99,
        'minimum_payment' => 45,
        'debt_type' => 'revolving',
    ])->assertRedirect();

    $debt = Debt::where('user_id', $user->id)->firstOrFail();
    expect($debt->name)->toBe('Visa');
    expect((float) $debt->balance)->toBe(1500.0);
});

test('storing a debt requires a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'balance' => 100, 'minimum_payment' => 10, 'debt_type' => 'installment',
    ])->assertSessionHasErrors('name');
});

test('updates an owned debt', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['name' => 'Old name']);

    $this->actingAs($user)->patch("/payoff-plan/debts/{$debt->id}", [
        'name' => 'New name', 'balance' => 200, 'minimum_payment' => 20, 'debt_type' => 'installment',
    ])->assertRedirect();

    expect($debt->fresh()->name)->toBe('New name');
});

test('cannot update another user\'s debt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $debt = Debt::factory()->for($owner)->create();

    $this->actingAs($intruder)->patch("/payoff-plan/debts/{$debt->id}", [
        'name' => 'Hacked', 'balance' => 1, 'minimum_payment' => 1, 'debt_type' => 'installment',
    ])->assertForbidden();
});

test('deletes an owned debt', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();

    $this->actingAs($user)->delete("/payoff-plan/debts/{$debt->id}")->assertRedirect();

    expect(Debt::find($debt->id))->toBeNull();
});

test('cannot delete another user\'s debt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $debt = Debt::factory()->for($owner)->create();

    $this->actingAs($intruder)->delete("/payoff-plan/debts/{$debt->id}")->assertForbidden();

    expect(Debt::find($debt->id))->not->toBeNull();
});

test('updates strategy and extra monthly payment settings', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 0]);

    $this->actingAs($user)->patch('/payoff-plan/settings', [
        'strategy' => 'snowball', 'extra_monthly' => 150,
    ])->assertRedirect();

    $settings = Settings::where('user_id', $user->id)->firstOrFail();
    expect($settings->strategy)->toBe('snowball');
    expect((float) $settings->extra_monthly)->toBe(150.0);
});

test('rejects an invalid strategy value', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/payoff-plan/settings', [
        'strategy' => 'lowest-hanging-fruit', 'extra_monthly' => 0,
    ])->assertSessionHasErrors('strategy');
});
