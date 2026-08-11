<?php

use App\Services\TrajectoryReportService;

test('simulatePayoff clears a single debt in the expected number of months', function () {
    $service = new TrajectoryReportService;

    // ₱1200 balance, ₱100/mo, 0% interest → exactly 12 months.
    $result = $service->simulatePayoff([
        ['balance' => 1200.0, 'perMonth' => 100.0, 'monthlyRate' => 0.0],
    ]);

    expect($result['months'])->toBe(12);
    expect($result['onTrack'])->toBeTrue();
    expect($result['perDebtMonths'][0])->toBe(12);
});

test('simulatePayoff marks a debt not-on-track when the payment cannot cover accrued interest', function () {
    $service = new TrajectoryReportService;

    // ₱10 monthly interest at this balance/rate, but perMonth is only ₱5 — never clears.
    $result = $service->simulatePayoff([
        ['balance' => 10000.0, 'perMonth' => 5.0, 'monthlyRate' => 0.01],
    ]);

    expect($result['months'])->toBeNull();
    expect($result['onTrack'])->toBeFalse();
    expect($result['perDebtMonths'][0])->toBeNull();
});

test('simulatePayoff directs extraMonthly at the highest-rate active debt (avalanche)', function () {
    $service = new TrajectoryReportService;

    $result = $service->simulatePayoff([
        ['balance' => 1200.0, 'perMonth' => 100.0, 'monthlyRate' => 0.0], // lower priority
        ['balance' => 100.0, 'perMonth' => 10.0, 'monthlyRate' => 0.02],  // higher rate, clears with the extra
    ], extraMonthly: 90.0);

    // The second debt gets the extra payment; after interest, it needs 2 months to clear.
    // Month 1: 100 + 2 interest - 100 payment = 2.0 remaining
    // Month 2: 2 + 0.04 interest - 100 payment = 0 (cleared)
    expect($result['perDebtMonths'][1])->toBe(2);
});

test('payoffInputs maps a debt collection into the balance/perMonth/monthlyRate shape simulatePayoff expects', function () {
    $service = new TrajectoryReportService;
    $debt = \App\Models\Debt::factory()->make([
        'balance' => 5000, 'per_month' => 250, 'interest_rate' => 12, 'interest_rate_period' => 'annual',
    ]);

    $inputs = $service->payoffInputs(collect([$debt]));

    expect($inputs)->toHaveCount(1);
    expect($inputs[0]['balance'])->toBe(5000.0);
    expect($inputs[0]['perMonth'])->toBe(250.0);
    expect($inputs[0]['monthlyRate'])->toBe(0.01); // 12% annual → 1%/mo
});
