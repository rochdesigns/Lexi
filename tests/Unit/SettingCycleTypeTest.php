<?php

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a newly created setting defaults to a fixed_days cycle type with no monthly start day', function () {
    $setting = Setting::factory()->create();

    expect($setting->cycle_type)->toBe('fixed_days');
    expect($setting->monthly_start_day)->toBeNull();
});

test('cycle_type and monthly_start_day are mass-assignable', function () {
    $setting = Setting::factory()->create(['cycle_type' => 'monthly', 'monthly_start_day' => 25]);

    expect($setting->fresh()->cycle_type)->toBe('monthly');
    expect($setting->fresh()->monthly_start_day)->toBe(25);
});
