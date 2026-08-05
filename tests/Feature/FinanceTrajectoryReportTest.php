<?php

use App\Models\Setting;
use App\Models\User;

test('settings store financial independence planning fields', function () {
    $user = User::factory()->create();

    $setting = Setting::create([
        'user_id' => $user->id,
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'fi_number' => 25000000,
        'fi_expected_return_rate' => 7.5,
        'emergency_fund_target_months' => 4,
        'investment_monthly_contribution_target' => 15000,
    ]);

    $fresh = $setting->fresh();

    expect((float) $fresh->fi_number)->toBe(25000000.0)
        ->and((float) $fresh->fi_expected_return_rate)->toBe(7.5)
        ->and($fresh->emergency_fund_target_months)->toBe(4)
        ->and((float) $fresh->investment_monthly_contribution_target)->toBe(15000.0);
});

test('financial independence fields fall back to sensible defaults', function () {
    $user = User::factory()->create();

    $setting = Setting::create(['user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30])->fresh();

    expect($setting->fi_number)->toBeNull()
        ->and((float) $setting->fi_expected_return_rate)->toBe(6.0)
        ->and($setting->emergency_fund_target_months)->toBe(6)
        ->and($setting->investment_monthly_contribution_target)->toBeNull();
});
