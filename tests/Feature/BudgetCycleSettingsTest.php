<?php

use App\Models\User;

test('guests are redirected to login', function () {
    $this->get('/settings/budget-cycle')->assertRedirect('/login');
});

test('budget cycle settings page renders with current values', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 14,
        'budget_cycle_anchor' => '2026-06-01',
    ]);

    $this->actingAs($user)
        ->get('/settings/budget-cycle')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/BudgetCycle')
            ->where('cycleDays', 14)
            ->where('cycleAnchor', '2026-06-01')
        );
});

test('cycle days and anchor can be updated', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->patch('/settings/budget-cycle', [
            'cycle_type' => 'fixed_days',
            'cycle_days' => 14,
            'cycle_anchor' => '2026-06-15',
        ])
        ->assertRedirectToRoute('budget-cycle.edit');

    $user->setting->refresh();
    expect($user->setting->budget_cycle_days)->toBe(14);
    expect($user->setting->budget_cycle_anchor->toDateString())->toBe('2026-06-15');
});

test('cycle_days must be between 1 and 365', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->patch('/settings/budget-cycle', [
            'cycle_days' => 0,
            'cycle_anchor' => '2026-07-01',
        ])
        ->assertSessionHasErrors('cycle_days');

    $this->actingAs($user)
        ->patch('/settings/budget-cycle', [
            'cycle_days' => 366,
            'cycle_anchor' => '2026-07-01',
        ])
        ->assertSessionHasErrors('cycle_days');
});

test('cycle_anchor must be a valid date', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->patch('/settings/budget-cycle', [
            'cycle_days' => 30,
            'cycle_anchor' => 'not-a-date',
        ])
        ->assertSessionHasErrors('cycle_anchor');
});

test('budget cycle settings page exposes the current cycle type and a suggested monthly start day', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 31,
        'budget_cycle_anchor' => '2026-06-24',
    ]);

    $this->actingAs($user)
        ->get('/settings/budget-cycle')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/BudgetCycle')
            ->where('cycleType', 'fixed_days')
            ->where('monthlyStartDay', null)
            // The current fixed-days cycle is Jul 25 – Aug 24 2026, so day 25 is
            // what "Monthly" should default to if the user switches to it.
            ->where('suggestedMonthlyStartDay', 25)
        );
});

test('switching to monthly cycle type persists cycle_type and monthly_start_day', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 31,
        'budget_cycle_anchor' => '2026-06-24',
    ]);

    $this->actingAs($user)
        ->patch('/settings/budget-cycle', [
            'cycle_type' => 'monthly',
            'monthly_start_day' => 25,
        ])
        ->assertRedirectToRoute('budget-cycle.edit');

    $user->setting->refresh();
    expect($user->setting->cycle_type)->toBe('monthly');
    expect($user->setting->monthly_start_day)->toBe(25);
});

test('monthly_start_day must be between 1 and 31 when cycle_type is monthly', function () {
    $user = User::factory()->create();
    $user->setting()->create(['theme' => 'ocean', 'budget_cycle_days' => 30, 'budget_cycle_anchor' => '2026-07-01']);

    $this->actingAs($user)
        ->patch('/settings/budget-cycle', ['cycle_type' => 'monthly', 'monthly_start_day' => 32])
        ->assertSessionHasErrors('monthly_start_day');

    $this->actingAs($user)
        ->patch('/settings/budget-cycle', ['cycle_type' => 'monthly', 'monthly_start_day' => 0])
        ->assertSessionHasErrors('monthly_start_day');
});

test('switching back to fixed_days persists cycle_days and cycle_anchor as before', function () {
    $user = User::factory()->create();
    $user->setting()->create(['theme' => 'ocean', 'budget_cycle_days' => 30, 'budget_cycle_anchor' => '2026-07-01', 'cycle_type' => 'monthly', 'monthly_start_day' => 25]);

    $this->actingAs($user)
        ->patch('/settings/budget-cycle', [
            'cycle_type' => 'fixed_days',
            'cycle_days' => 14,
            'cycle_anchor' => '2026-06-15',
        ])
        ->assertRedirectToRoute('budget-cycle.edit');

    $user->setting->refresh();
    expect($user->setting->cycle_type)->toBe('fixed_days');
    expect($user->setting->budget_cycle_days)->toBe(14);
});
