<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Settings;
use App\Models\User;
use App\Services\DebtFocus\LedgerService;

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
    expect($props['progress'])->toBeNull();
    expect($props['pace'])->toBeNull();
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

test('links a debt to a liability account, keeping the account balance', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000]);
    $account = Account::factory()->for($user)->liability()->create(['balance' => 800]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'account',
    ])->assertRedirect();

    expect($debt->fresh()->linked_account_id)->toBe($account->id);
    expect((float) $debt->fresh()->balance)->toBe(800.0);
    expect((float) $account->fresh()->balance)->toBe(800.0);
});

test('links a debt to a liability account, keeping the debt balance', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000]);
    $account = Account::factory()->for($user)->liability()->create(['balance' => 800]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'debt',
    ])->assertRedirect();

    expect($debt->fresh()->linked_account_id)->toBe($account->id);
    expect((float) $debt->fresh()->balance)->toBe(1000.0);
    expect((float) $account->fresh()->balance)->toBe(1000.0);
});

test('cannot link a debt to another user\'s account', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $debt = Debt::factory()->for($intruder)->create();
    $account = Account::factory()->for($owner)->liability()->create();

    $this->actingAs($intruder)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'account',
    ])->assertSessionHasErrors('linked_account_id');

    expect($debt->fresh()->linked_account_id)->toBeNull();
});

test('cannot link a debt to a non-liability account', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create(['is_liability' => false]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'account',
    ])->assertSessionHasErrors('linked_account_id');

    expect($debt->fresh()->linked_account_id)->toBeNull();
});

test('cannot link another user\'s debt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $debt = Debt::factory()->for($owner)->create();
    $account = Account::factory()->for($intruder)->liability()->create();

    $this->actingAs($intruder)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'account',
    ])->assertForbidden();
});

test('unlinking a debt snapshots the synced balance and clears the link', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create(['balance' => 800]);
    $debt = Debt::factory()->for($user)->create(['balance' => 800, 'linked_account_id' => $account->id]);

    (new LedgerService)->recordExpense($account, 50, 'Purchase');

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/unlink")->assertRedirect();

    expect($debt->fresh()->linked_account_id)->toBeNull();
    expect((float) $debt->fresh()->balance)->toBe(850.0);
});

test('cannot unlink another user\'s debt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = Account::factory()->for($owner)->liability()->create();
    $debt = Debt::factory()->for($owner)->create(['linked_account_id' => $account->id]);

    $this->actingAs($intruder)->post("/payoff-plan/debts/{$debt->id}/unlink")->assertForbidden();

    expect($debt->fresh()->linked_account_id)->toBe($account->id);
});

test('renders a linked debt\'s live balance from its ledger account, not its own column', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create(['balance' => 800]);
    $debt = Debt::factory()->for($user)->create([
        'balance' => 800, 'minimum_payment' => 100, 'monthly_rate' => null, 'debt_type' => 'revolving',
        'linked_account_id' => $account->id,
    ]);

    (new LedgerService)->recordExpense($account, 50, 'Purchase');

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props']['debts'];

    expect((float) $props[0]['balance'])->toBe(850.0);
    expect($props[0]['linked_account_id'])->toBe($account->id);
    expect($props[0]['linked_account_name'])->toBe($account->name);
});

test('a newly created debt starts with starting_balance equal to its balance and zero percent paid', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props']['debts'][0];

    expect((float) $debt->fresh()->starting_balance)->toBe(1000.0);
    expect($props['starting_balance'])->toBe(1000.0);
    expect($props['percent_paid'])->toBe(0.0);
});

test('editing a debt\'s balance does not reset its starting_balance or its percent_paid progress', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['name' => 'Visa', 'balance' => 1000]);

    $this->actingAs($user)->patch("/payoff-plan/debts/{$debt->id}", [
        'name' => 'Visa', 'balance' => 750, 'minimum_payment' => 20, 'debt_type' => 'installment',
    ])->assertRedirect();

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props']['debts'][0];

    expect($props['starting_balance'])->toBe(1000.0);
    expect($props['percent_paid'])->toBe(0.25);
});

test('exposes overall progress totals, percent paid, and reached milestones', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000]);
    $debt->update(['balance' => 750]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $progress = $response->viewData('page')['props']['progress'];

    expect($progress['totalStartingBalance'])->toBe(1000.0);
    expect($progress['totalBalance'])->toBe(750.0);
    expect($progress['totalPaidOff'])->toBe(250.0);
    expect($progress['percentPaid'])->toBe(0.25);
    expect($progress['milestonesReached'])->toBe([25]);
});

test('counts debts that have reached a zero balance for the paid-off milestone', function () {
    $user = User::factory()->create();
    $paidOff = Debt::factory()->for($user)->create(['balance' => 500]);
    $paidOff->update(['balance' => 0]);
    Debt::factory()->for($user)->create(['balance' => 300]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $progress = $response->viewData('page')['props']['progress'];

    expect($progress['paidOffCount'])->toBe(1);
    expect($progress['totalDebtsCount'])->toBe(2);
});

test('pace is null when the plan started less than a month ago', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 1000]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();

    expect($response->viewData('page')['props']['pace'])->toBeNull();
});

test('pace reports being ahead of the plan when the actual balance is lower than projected', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null]);
    Debt::where('id', $debt->id)->update(['created_at' => now()->subMonths(3)]);
    $debt->update(['balance' => 850]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $pace = $response->viewData('page')['props']['pace'];

    expect($pace['elapsedMonths'])->toBe(3);
    expect($pace['status'])->toBe('ahead');
    expect($pace['deltaAmount'])->toBe(50.0);
});

test('pace reports being behind the plan when the actual balance is higher than projected', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null]);
    Debt::where('id', $debt->id)->update(['created_at' => now()->subMonths(3)]);
    $debt->update(['balance' => 950]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $pace = $response->viewData('page')['props']['pace'];

    expect($pace['elapsedMonths'])->toBe(3);
    expect($pace['status'])->toBe('behind');
    expect($pace['deltaAmount'])->toBe(50.0);
});

test('index exposes the user\'s liability accounts, with balance, for the debt-linking picker', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->liability()->create(['name' => 'Visa', 'balance' => 640]);
    Account::factory()->for($user)->create(['name' => 'Checking', 'is_liability' => false]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $liabilityAccounts = $response->viewData('page')['props']['liabilityAccounts'];

    expect(collect($liabilityAccounts)->pluck('name')->all())->toBe(['Visa']);
    expect((float) $liabilityAccounts[0]['balance'])->toBe(640.0);
});
