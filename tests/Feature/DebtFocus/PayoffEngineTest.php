<?php

use App\Models\DebtFocus\Debt;
use App\Models\User;
use App\Services\DebtFocus\PayoffEngine;

test('a single debt with no interest and no extra payment pays off in balance divided by minimum-payment months', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result['months'])->toBe(12);
    expect($result['onTrack'])->toBeTrue();
    expect($result['perDebt'][0]['debtId'])->toBe($debt->id);
    expect($result['perDebt'][0]['payoffMonth'])->toBe(12);
    expect($result['perDebt'][0]['totalInterest'])->toBeNull();
});

test('a debt whose minimum payment cannot cover accrued interest is marked not on track', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 10000, 'minimum_payment' => 5, 'monthly_rate' => 12]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result['months'])->toBeNull();
    expect($result['onTrack'])->toBeFalse();
    expect($result['perDebt'][0]['payoffMonth'])->toBeNull();
    expect($result['perDebt'][0]['totalInterest'])->toBeNull();
});

test('avalanche directs the extra payment at the highest-monthly-rate active debt', function () {
    $user = User::factory()->create();
    $lowRate = Debt::factory()->for($user)->create(['balance' => 500, 'minimum_payment' => 50, 'monthly_rate' => 0]);
    $highRate = Debt::factory()->for($user)->create(['balance' => 200, 'minimum_payment' => 20, 'monthly_rate' => 2]);

    $result = (new PayoffEngine)->simulate(collect([$lowRate, $highRate]), 'avalanche', extraMonthly: 200);

    $highRateResult = collect($result['perDebt'])->firstWhere('debtId', $highRate->id);
    expect($highRateResult['payoffMonth'])->toBe(1);
});

test('a fully paid-off debt is excluded and the simulation reports already-done', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 0]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result)->toBe(['strategy' => 'avalanche', 'months' => 0, 'onTrack' => true, 'perDebt' => [], 'trajectory' => null]);
});

test('withTrajectory reports the total balance across all debts at the end of each month', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 300, 'minimum_payment' => 100, 'monthly_rate' => null]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche', withTrajectory: true);

    expect($result['trajectory'])->toBe([300.0, 200.0, 100.0, 0.0]);
});

test('withTrajectory sums balances across multiple debts month by month', function () {
    $user = User::factory()->create();
    $a = Debt::factory()->for($user)->create(['balance' => 200, 'minimum_payment' => 100, 'monthly_rate' => null]);
    $b = Debt::factory()->for($user)->create(['balance' => 100, 'minimum_payment' => 50, 'monthly_rate' => null]);

    $result = (new PayoffEngine)->simulate(collect([$a, $b]), 'avalanche', withTrajectory: true);

    expect($result['trajectory'])->toBe([300.0, 150.0, 0.0]);
});

test('without withTrajectory the trajectory key is null', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 300, 'minimum_payment' => 100, 'monthly_rate' => null]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result['trajectory'])->toBeNull();
});

test('an unsupported strategy throws', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();

    (new PayoffEngine)->simulate(collect([$debt]), 'lowest-hanging-fruit');
})->throws(InvalidArgumentException::class);

test('snowball directs the extra payment at the lowest-starting-balance active debt regardless of rate', function () {
    $user = User::factory()->create();
    $highBalance = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 50, 'monthly_rate' => 24]);
    $lowBalance = Debt::factory()->for($user)->create(['balance' => 100, 'minimum_payment' => 10, 'monthly_rate' => 0]);

    $result = (new PayoffEngine)->simulate(collect([$highBalance, $lowBalance]), 'snowball', extraMonthly: 200);

    $lowBalanceResult = collect($result['perDebt'])->firstWhere('debtId', $lowBalance->id);
    expect($lowBalanceResult['payoffMonth'])->toBe(1);
});

test('snowball moves the extra payment to the next-lowest-starting-balance debt once the current target pays off', function () {
    $user = User::factory()->create();
    $tiny = Debt::factory()->for($user)->create(['balance' => 50, 'minimum_payment' => 50, 'monthly_rate' => 0]);
    $huge = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 10, 'monthly_rate' => 0]);
    $middle = Debt::factory()->for($user)->create(['balance' => 200, 'minimum_payment' => 10, 'monthly_rate' => 0]);

    $result = (new PayoffEngine)->simulate(collect([$tiny, $huge, $middle]), 'snowball', extraMonthly: 300);

    $middleResult = collect($result['perDebt'])->firstWhere('debtId', $middle->id);
    $hugeResult = collect($result['perDebt'])->firstWhere('debtId', $huge->id);

    expect($middleResult['payoffMonth'])->toBe(1);
    expect($hugeResult['payoffMonth'])->not->toBe(1);
});

test('a freed minimum payment rolls into the extra-payment pool once its debt pays off', function () {
    $user = User::factory()->create();
    $a = Debt::factory()->for($user)->create(['balance' => 200, 'minimum_payment' => 100, 'monthly_rate' => null]);
    $b = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 100, 'monthly_rate' => null]);

    $result = (new PayoffEngine)->simulate(collect([$a, $b]), 'avalanche', extraMonthly: 0);

    $aResult = collect($result['perDebt'])->firstWhere('debtId', $a->id);
    $bResult = collect($result['perDebt'])->firstWhere('debtId', $b->id);

    expect($aResult['payoffMonth'])->toBe(2);
    expect($bResult['payoffMonth'])->toBe(6);
});

test('interest accrues and compounds to an exact, hand-computable totalInterest', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 300, 'monthly_rate' => 1]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result['perDebt'][0]['payoffMonth'])->toBe(4);
    expect($result['perDebt'][0]['totalInterest'])->toBe(22.48);
});

test('a negative extra monthly payment throws', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();

    (new PayoffEngine)->simulate(collect([$debt]), 'avalanche', extraMonthly: -50);
})->throws(InvalidArgumentException::class);
