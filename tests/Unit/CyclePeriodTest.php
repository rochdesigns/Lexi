<?php

use App\Support\CyclePeriod;
use Carbon\Carbon;

test('label formats a full calendar month range as Month Year', function () {
    $period = new CyclePeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

    expect($period->label())->toBe('August 2026');
    expect($period->isCalendarMonth())->toBeTrue();
});

test('label formats a same-year non-calendar range as M j – M j, Y', function () {
    $period = new CyclePeriod(Carbon::parse('2026-07-25'), Carbon::parse('2026-08-24'));

    expect($period->label())->toBe('Jul 25 – Aug 24, 2026');
    expect($period->isCalendarMonth())->toBeFalse();
});

test('label formats a range spanning years as M j, Y – M j, Y', function () {
    $period = new CyclePeriod(Carbon::parse('2026-12-15'), Carbon::parse('2027-01-14'));

    expect($period->label())->toBe('Dec 15, 2026 – Jan 14, 2027');
});

test('containsToday reflects whether now() falls within the period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-11'));

    $inside = new CyclePeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));
    $outside = new CyclePeriod(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

    expect($inside->containsToday())->toBeTrue();
    expect($outside->containsToday())->toBeFalse();

    Carbon::setTestNow();
});

test('toArray exposes ISO date strings and the label', function () {
    $period = new CyclePeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'));

    expect($period->toArray())->toBe([
        'start' => '2026-08-01',
        'end' => '2026-08-31',
        'label' => 'August 2026',
    ]);
});
