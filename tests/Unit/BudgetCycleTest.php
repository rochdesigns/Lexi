<?php

use App\Models\Setting;
use App\Services\BudgetCycle;
use Carbon\Carbon;

// ── fixed_days (existing behavior — must match Setting::cycleForDate exactly) ──

test('containing returns the first cycle for a date inside it', function () {
    $setting = new Setting(['cycle_type' => 'fixed_days', 'budget_cycle_days' => 30, 'budget_cycle_anchor' => Carbon::parse('2026-07-01')]);
    $cycle = new BudgetCycle($setting);

    $period = $cycle->containing(Carbon::parse('2026-07-15'));

    expect($period->start->toDateString())->toBe('2026-07-01');
    expect($period->end->toDateString())->toBe('2026-07-30');
});

test('containing returns a later cycle for a date past the first', function () {
    $setting = new Setting(['cycle_type' => 'fixed_days', 'budget_cycle_days' => 30, 'budget_cycle_anchor' => Carbon::parse('2026-07-01')]);
    $cycle = new BudgetCycle($setting);

    $period = $cycle->containing(Carbon::parse('2026-08-05'));

    expect($period->start->toDateString())->toBe('2026-07-31');
    expect($period->end->toDateString())->toBe('2026-08-29');
});

test('containing works for non-30-day intervals', function () {
    $setting = new Setting(['cycle_type' => 'fixed_days', 'budget_cycle_days' => 14, 'budget_cycle_anchor' => Carbon::parse('2026-07-01')]);
    $cycle = new BudgetCycle($setting);

    $period = $cycle->containing(Carbon::parse('2026-07-20'));

    expect($period->start->toDateString())->toBe('2026-07-15');
    expect($period->end->toDateString())->toBe('2026-07-28');
});

test('next returns the following fixed-days period contiguous with the given one', function () {
    $setting = new Setting(['cycle_type' => 'fixed_days', 'budget_cycle_days' => 31, 'budget_cycle_anchor' => Carbon::parse('2026-06-24')]);
    $cycle = new BudgetCycle($setting);

    $current = $cycle->containing(Carbon::parse('2026-08-01'));
    $next = $cycle->next($current);

    expect($current->start->toDateString())->toBe('2026-07-25');
    expect($current->end->toDateString())->toBe('2026-08-24');
    expect($next->start->toDateString())->toBe('2026-08-25');
    expect($next->end->toDateString())->toBe('2026-09-24');
});

test('previous returns the preceding fixed-days period contiguous with the given one', function () {
    $setting = new Setting(['cycle_type' => 'fixed_days', 'budget_cycle_days' => 31, 'budget_cycle_anchor' => Carbon::parse('2026-06-24')]);
    $cycle = new BudgetCycle($setting);

    $current = $cycle->containing(Carbon::parse('2026-08-01'));
    $previous = $cycle->previous($current);

    expect($previous->start->toDateString())->toBe('2026-06-24');
    expect($previous->end->toDateString())->toBe('2026-07-24');
});

test('current uses now() when no date is given', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));
    $setting = new Setting(['cycle_type' => 'fixed_days', 'budget_cycle_days' => 30, 'budget_cycle_anchor' => Carbon::parse('2026-07-01')]);
    $cycle = new BudgetCycle($setting);

    $period = $cycle->current();

    expect($period->start->toDateString())->toBe('2026-07-31');
    Carbon::setTestNow();
});

test('a null setting falls back to the calendar month containing the date', function () {
    $cycle = new BudgetCycle(null);

    $period = $cycle->containing(Carbon::parse('2026-08-11'));

    expect($period->start->toDateString())->toBe('2026-08-01');
    expect($period->end->toDateString())->toBe('2026-08-31');
});

// ── monthly (new: calendar-month-anchored to a configured day-of-month) ──

test('monthly cycle anchored to day 25 produces Jul 25 – Aug 24 style boundaries', function () {
    $setting = new Setting(['cycle_type' => 'monthly', 'monthly_start_day' => 25]);
    $cycle = new BudgetCycle($setting);

    $july = $cycle->containing(Carbon::parse('2026-08-01'));
    expect($july->start->toDateString())->toBe('2026-07-25');
    expect($july->end->toDateString())->toBe('2026-08-24');

    $august = $cycle->next($july);
    expect($august->start->toDateString())->toBe('2026-08-25');
    expect($august->end->toDateString())->toBe('2026-09-24');

    $september = $cycle->next($august);
    expect($september->start->toDateString())->toBe('2026-09-25');
    expect($september->end->toDateString())->toBe('2026-10-24');
});

test('monthly cycle anchored to the 31st clamps to the last day of short months', function () {
    $setting = new Setting(['cycle_type' => 'monthly', 'monthly_start_day' => 31]);
    $cycle = new BudgetCycle($setting);

    // January has 31 days, so the cycle starts exactly on the 31st.
    $jan = $cycle->containing(Carbon::parse('2026-01-31'));
    expect($jan->start->toDateString())->toBe('2026-01-31');
    // February 2026 (non-leap) only has 28 days, so the next start clamps to
    // Feb 28 and this cycle's end is the day before that.
    expect($jan->end->toDateString())->toBe('2026-02-27');

    $feb = $cycle->next($jan);
    expect($feb->start->toDateString())->toBe('2026-02-28');
    // March has 31 days, so the cycle resumes the true 31st and runs a full month.
    expect($feb->end->toDateString())->toBe('2026-03-30');
});

test('monthly cycle anchored to the 31st handles the Feb 29 leap-year case', function () {
    $setting = new Setting(['cycle_type' => 'monthly', 'monthly_start_day' => 31]);
    $cycle = new BudgetCycle($setting);

    $jan = $cycle->containing(Carbon::parse('2028-01-31'));
    expect($jan->start->toDateString())->toBe('2028-01-31');
    // 2028 is a leap year: February has 29 days, so the clamp lands one day
    // later than the 2026 case above.
    expect($jan->end->toDateString())->toBe('2028-02-28');

    $feb = $cycle->next($jan);
    expect($feb->start->toDateString())->toBe('2028-02-29');
});

test('previous is the inverse of next for a monthly cycle', function () {
    $setting = new Setting(['cycle_type' => 'monthly', 'monthly_start_day' => 25]);
    $cycle = new BudgetCycle($setting);

    $august = $cycle->containing(Carbon::parse('2026-08-25'));
    $july = $cycle->previous($august);

    expect($july->start->toDateString())->toBe('2026-07-25');
    expect($july->end->toDateString())->toBe('2026-08-24');
});

test('a monthly setting with no explicit start day defaults to the 1st', function () {
    $setting = new Setting(['cycle_type' => 'monthly', 'monthly_start_day' => null]);
    $cycle = new BudgetCycle($setting);

    $period = $cycle->containing(Carbon::parse('2026-08-11'));

    expect($period->start->toDateString())->toBe('2026-08-01');
    expect($period->end->toDateString())->toBe('2026-08-31');
});
