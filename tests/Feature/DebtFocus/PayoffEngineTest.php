<?php

use App\Models\DebtFocus\Debt;
use App\Models\User;
use App\Services\DebtFocus\PayoffEngine;

test('a single debt with no interest and no extra payment pays off in balance divided by minimum-payment months', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'apr' => null]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result['months'])->toBe(12);
    expect($result['onTrack'])->toBeTrue();
    expect($result['perDebt'][0]['debtId'])->toBe($debt->id);
    expect($result['perDebt'][0]['payoffMonth'])->toBe(12);
    expect($result['perDebt'][0]['totalInterest'])->toBeNull();
});

test('a debt whose minimum payment cannot cover accrued interest is marked not on track', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 10000, 'minimum_payment' => 5, 'apr' => 12]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result['months'])->toBeNull();
    expect($result['onTrack'])->toBeFalse();
    expect($result['perDebt'][0]['payoffMonth'])->toBeNull();
});

test('avalanche directs the extra payment at the highest-apr active debt', function () {
    $user = User::factory()->create();
    $lowRate = Debt::factory()->for($user)->create(['balance' => 500, 'minimum_payment' => 50, 'apr' => 0]);
    $highRate = Debt::factory()->for($user)->create(['balance' => 200, 'minimum_payment' => 20, 'apr' => 24]);

    $result = (new PayoffEngine)->simulate(collect([$lowRate, $highRate]), 'avalanche', extraMonthly: 200);

    $highRateResult = collect($result['perDebt'])->firstWhere('debtId', $highRate->id);
    expect($highRateResult['payoffMonth'])->toBe(1);
});

test('a fully paid-off debt is excluded and the simulation reports already-done', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 0]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result)->toBe(['strategy' => 'avalanche', 'months' => 0, 'onTrack' => true, 'perDebt' => []]);
});

test('an unsupported strategy throws', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();

    (new PayoffEngine)->simulate(collect([$debt]), 'lowest-hanging-fruit');
})->throws(InvalidArgumentException::class);
