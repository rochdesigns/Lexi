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
