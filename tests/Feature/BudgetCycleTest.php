<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;

test('new user registration creates setting with budget_cycle_days = 30', function () {
    (new CreateNewUser)->create([
        'name' => 'Cycle Test',
        'email' => 'cycle@test.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'cycle@test.com')->first();

    expect($user->setting)->not->toBeNull();
    expect($user->setting->budget_cycle_days)->toBe(30);
    expect($user->setting->budget_cycle_anchor)->not->toBeNull();
});

test('cycleForDate returns correct cycle for a date in the first cycle', function () {
    $setting = new Setting([
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => Carbon::parse('2026-07-01'),
    ]);

    $result = $setting->cycleForDate(Carbon::parse('2026-07-15'));

    expect($result['start']->toDateString())->toBe('2026-07-01');
    expect($result['end']->toDateString())->toBe('2026-07-30');
    expect($result['index'])->toBe(0);
});

test('cycleForDate returns correct cycle for a date in a later cycle', function () {
    $setting = new Setting([
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => Carbon::parse('2026-07-01'),
    ]);

    $result = $setting->cycleForDate(Carbon::parse('2026-08-05'));

    expect($result['start']->toDateString())->toBe('2026-07-31');
    expect($result['end']->toDateString())->toBe('2026-08-29');
    expect($result['index'])->toBe(1);
});

test('cycleForDate works for non-30-day intervals', function () {
    $setting = new Setting([
        'budget_cycle_days' => 14,
        'budget_cycle_anchor' => Carbon::parse('2026-07-01'),
    ]);

    $result = $setting->cycleForDate(Carbon::parse('2026-07-20'));

    // Jul 20 is 19 days after Jul 1; index = floor(19/14) = 1
    // start = Jul 1 + 14 = Jul 15; end = Jul 15 + 13 = Jul 28
    expect($result['start']->toDateString())->toBe('2026-07-15');
    expect($result['end']->toDateString())->toBe('2026-07-28');
    expect($result['index'])->toBe(1);
});

test('cycle end date is start + cycle_days - 1', function () {
    $setting = new Setting([
        'budget_cycle_days' => 7,
        'budget_cycle_anchor' => Carbon::parse('2026-07-01'),
    ]);

    $result = $setting->cycleForDate(Carbon::parse('2026-07-03'));

    expect((int) $result['start']->diffInDays($result['end']))->toBe(6);
});
