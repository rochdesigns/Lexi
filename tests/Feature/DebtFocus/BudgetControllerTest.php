<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\Settings;
use App\Models\User;
use App\Services\DebtFocus\LedgerService;

test('guests are redirected to login', function () {
    $this->get('/budget')->assertRedirect('/login');
});

test('renders empty state for a user with nothing set up', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['debtMinimums'])->toBe([]);
    expect($props['bills'])->toBe([]);
    expect($props['envelopes'])->toBe([]);
    expect($props['sweepCandidates'])->toBe([]);
    expect($props['priorityDebt'])->toBeNull();
    expect((float) $props['leftover'])->toBe(0.0);
});

test('defaults to the current year and month when none are given', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['year'])->toBe((int) now()->year);
    expect($props['month'])->toBe((int) now()->month);
});

test('shows debt minimums pulled live from active debts', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'Maya CC', 'minimum_payment' => 75]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $minimums = $response->viewData('page')['props']['debtMinimums'];

    expect($minimums)->toHaveCount(1);
    expect($minimums[0]['name'])->toBe('Maya CC');
    expect((float) $minimums[0]['minimum_payment'])->toBe(75.0);
});

test('shows bill and envelope periods for the requested month', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Electricity', 'expected_amount' => 60]);
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 200]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['bills'])->toHaveCount(1);
    expect($props['bills'][0]['name'])->toBe('Electricity');
    expect((float) $props['bills'][0]['expected_amount'])->toBe(60.0);
    expect($props['envelopes'])->toHaveCount(1);
    expect($props['envelopes'][0]['name'])->toBe('Groceries');
    expect((float) $props['envelopes'][0]['target'])->toBe(200.0);
    expect((float) $props['envelopes'][0]['spent'])->toBe(0.0);
});

test('computes leftover as income minus minimums, bills, and envelope targets', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 3000, 'Paycheck', now()->setDate(2026, 8, 1));
    Debt::factory()->for($user)->create(['minimum_payment' => 200]);
    Bill::factory()->for($user)->create(['expected_amount' => 100]);
    Envelope::factory()->for($user)->create(['default_target' => 500]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect((float) $response->viewData('page')['props']['leftover'])->toBe(2200.0);
});

test('leftover never goes negative when assignments exceed income', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['minimum_payment' => 5000]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect((float) $response->viewData('page')['props']['leftover'])->toBe(0.0);
});

test('priorityDebt is the highest-priority debt that has a linked account', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    Debt::factory()->for($user)->create(['name' => 'Unlinked, high rate', 'monthly_rate' => 30, 'linked_account_id' => null]);
    $linkedAccount = Account::factory()->for($user)->liability()->create();
    $linked = Debt::factory()->for($user)->create(['name' => 'Linked, lower rate', 'monthly_rate' => 10, 'linked_account_id' => $linkedAccount->id]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();

    expect($response->viewData('page')['props']['priorityDebt']['id'])->toBe($linked->id);
});

test('priorityDebt is null when no debt has a linked account', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['linked_account_id' => null]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();

    expect($response->viewData('page')['props']['priorityDebt'])->toBeNull();
});

test('bill and envelope templates are listed regardless of active status', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Active bill', 'active' => true]);
    Bill::factory()->for($user)->create(['name' => 'Inactive bill', 'active' => false]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $templates = collect($response->viewData('page')['props']['billTemplates'])->pluck('name');

    expect($templates->all())->toContain('Active bill', 'Inactive bill');
});
