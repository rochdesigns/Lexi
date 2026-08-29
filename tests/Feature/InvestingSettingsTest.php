<?php

use App\Models\User;

test('guests are redirected to login', function () {
    $this->get('/settings/investing')->assertRedirect('/login');
});

test('investing settings page renders with current values', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
        'fi_number' => 5000000,
        'fi_expected_return_rate' => 7.5,
        'emergency_fund_target_months' => 9,
        'investment_monthly_contribution_target' => 15000,
    ]);

    $this->actingAs($user)
        ->get('/settings/investing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Investing')
            ->where('fiNumber', '5000000.00')
            ->where('fiExpectedReturnRate', '7.50')
            ->where('emergencyFundTargetMonths', 9)
            ->where('investmentMonthlyContributionTarget', '15000.00')
        );
});

test('investing settings page renders defaults when unset', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->get('/settings/investing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Investing')
            ->where('fiNumber', null)
            ->where('fiExpectedReturnRate', '6.00')
            ->where('emergencyFundTargetMonths', 6)
            ->where('investmentMonthlyContributionTarget', null)
        );
});

test('investing settings can be updated', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->patch('/settings/investing', [
            'fi_number' => 4200000,
            'fi_expected_return_rate' => 8,
            'emergency_fund_target_months' => 12,
            'investment_monthly_contribution_target' => 20000,
        ])
        ->assertRedirectToRoute('investing.edit');

    $user->setting->refresh();
    expect((float) $user->setting->fi_number)->toBe(4200000.0);
    expect((float) $user->setting->fi_expected_return_rate)->toBe(8.0);
    expect($user->setting->emergency_fund_target_months)->toBe(12);
    expect((float) $user->setting->investment_monthly_contribution_target)->toBe(20000.0);
});

test('investing settings fields can be cleared back to null', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
        'fi_number' => 5000000,
        'investment_monthly_contribution_target' => 15000,
    ]);

    $this->actingAs($user)
        ->patch('/settings/investing', [
            'fi_number' => null,
            'fi_expected_return_rate' => null,
            'emergency_fund_target_months' => null,
            'investment_monthly_contribution_target' => null,
        ])
        ->assertRedirectToRoute('investing.edit');

    $user->setting->refresh();
    expect($user->setting->fi_number)->toBeNull();
    expect($user->setting->investment_monthly_contribution_target)->toBeNull();
});

test('fi_expected_return_rate must be between 0 and 100', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->patch('/settings/investing', ['fi_expected_return_rate' => 150])
        ->assertSessionHasErrors('fi_expected_return_rate');
});

test('emergency_fund_target_months must be between 1 and 60', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->patch('/settings/investing', ['emergency_fund_target_months' => 0])
        ->assertSessionHasErrors('emergency_fund_target_months');

    $this->actingAs($user)
        ->patch('/settings/investing', ['emergency_fund_target_months' => 61])
        ->assertSessionHasErrors('emergency_fund_target_months');
});

test('fi_number cannot be negative', function () {
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => '2026-07-01',
    ]);

    $this->actingAs($user)
        ->patch('/settings/investing', ['fi_number' => -1])
        ->assertSessionHasErrors('fi_number');
});
