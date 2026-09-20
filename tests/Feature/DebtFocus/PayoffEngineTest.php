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

    expect($result)->toBe(['strategy' => 'avalanche', 'months' => 0, 'onTrack' => true, 'perDebt' => [], 'trajectory' => null, 'payments' => null]);
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

test('orderByPriority sorts by highest monthly rate first under avalanche', function () {
    $user = User::factory()->create();
    $lowRate = Debt::factory()->for($user)->create(['name' => 'Low rate', 'monthly_rate' => 5]);
    $highRate = Debt::factory()->for($user)->create(['name' => 'High rate', 'monthly_rate' => 20]);

    $ordered = (new PayoffEngine)->orderByPriority(collect([$lowRate, $highRate]), 'avalanche');

    expect($ordered->pluck('name')->all())->toBe(['High rate', 'Low rate']);
});

test('orderByPriority sorts by lowest balance first under snowball', function () {
    $user = User::factory()->create();
    $bigBalance = Debt::factory()->for($user)->create(['name' => 'Big balance', 'balance' => 5000]);
    $smallBalance = Debt::factory()->for($user)->create(['name' => 'Small balance', 'balance' => 500]);

    $ordered = (new PayoffEngine)->orderByPriority(collect([$bigBalance, $smallBalance]), 'snowball');

    expect($ordered->pluck('name')->all())->toBe(['Small balance', 'Big balance']);
});

test('a pinned priority debt takes the extra payment ahead of the strategy order', function () {
    $user = User::factory()->create();
    $highRate = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 50, 'monthly_rate' => 3]);
    $pinned = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 50, 'monthly_rate' => 0]);

    $result = (new PayoffEngine)->simulate(collect([$highRate, $pinned]), 'avalanche', extraMonthly: 950, priorityDebtId: $pinned->id);

    expect(collect($result['perDebt'])->firstWhere('debtId', $pinned->id)['payoffMonth'])->toBe(1);
    expect(collect($result['perDebt'])->firstWhere('debtId', $highRate->id)['payoffMonth'])->toBeGreaterThan(1);
});

test('a pinned priority debt is moved to the front of the ordered queue', function () {
    $user = User::factory()->create();
    $highRate = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 50, 'monthly_rate' => 3]);
    $pinned = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 50, 'monthly_rate' => 0]);

    $ordered = (new PayoffEngine)->orderByPriority(collect([$highRate, $pinned]), 'avalanche', $pinned->id);

    expect($ordered->first()->id)->toBe($pinned->id);
});

test('a debt whose minimum is smaller than its monthly interest is reported as going backwards', function () {
    $user = User::factory()->create();
    $stalled = Debt::factory()->for($user)->create(['balance' => 10000, 'minimum_payment' => 100, 'monthly_rate' => 1.4]);
    $falling = Debt::factory()->for($user)->create(['balance' => 10000, 'minimum_payment' => 500, 'monthly_rate' => 1.4]);

    $engine = new PayoffEngine;

    expect($engine->isGoingBackwards($stalled))->toBeTrue();
    expect($engine->isGoingBackwards($falling))->toBeFalse();
    expect($engine->minimumTopUpToClear($falling))->toBeNull();
    expect($engine->minimumTopUpToClear($stalled))->toBeGreaterThan(40.0);
});

test('the top-up suggested for a stalled debt is enough to make it fall again', function () {
    $user = User::factory()->create();
    $stalled = Debt::factory()->for($user)->create(['balance' => 10000, 'minimum_payment' => 100, 'monthly_rate' => 1.4]);

    $engine = new PayoffEngine;
    $stalled->minimum_payment = (float) $stalled->minimum_payment + $engine->minimumTopUpToClear($stalled);

    expect($engine->isGoingBackwards($stalled))->toBeFalse();
});

test('the extra-payment curve runs from zero to the cap and shortens the plan as it climbs', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 12000, 'minimum_payment' => 200, 'monthly_rate' => 1]);

    $curve = (new PayoffEngine)->extraCurve(collect([$debt]), 'avalanche', maxExtra: 1000, steps: 10);

    expect($curve)->toHaveCount(11);
    expect($curve[0]['extra'])->toBe(0.0);
    expect($curve[10]['extra'])->toBe(1000.0);
    expect($curve[10]['months'])->toBeLessThan($curve[0]['months']);
    expect($curve[10]['totalInterest'])->toBeLessThan($curve[0]['totalInterest']);
});

test('a payment splits into the month of interest it clears and the principal it knocks off', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 48200, 'monthly_rate' => 3.25, 'minimum_payment' => 2100]);

    $split = (new PayoffEngine)->splitPayment($debt, 9500);

    expect($split['interest'])->toBe(1566.5);
    expect($split['principal'])->toBe(7933.5);
    expect($split['balanceAfter'])->toBe(40266.5);
    expect($split['coversInterest'])->toBeTrue();
});

test('a payment smaller than the month of interest reports a negative principal and a growing balance', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 10000, 'monthly_rate' => 2, 'minimum_payment' => 50]);

    $split = (new PayoffEngine)->splitPayment($debt, 100);

    expect($split['interest'])->toBe(200.0);
    expect($split['principal'])->toBe(-100.0);
    expect($split['balanceAfter'])->toBe(10100.0);
    expect($split['coversInterest'])->toBeFalse();
});

test('a debt with no rate set puts the whole payment against principal', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 5000, 'monthly_rate' => null, 'minimum_payment' => 500]);

    $split = (new PayoffEngine)->splitPayment($debt, 1200);

    expect($split['interest'])->toBe(0.0);
    expect($split['principal'])->toBe(1200.0);
    expect($split['balanceAfter'])->toBe(3800.0);
});

test('paying more than the balance settles it at zero rather than going negative', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000, 'monthly_rate' => 1, 'minimum_payment' => 100]);

    $split = (new PayoffEngine)->splitPayment($debt, 5000);

    expect($split['balanceAfter'])->toBe(0.0);
});

test('a suggested minimum covers the month of interest plus a slice of principal', function () {
    $engine = new PayoffEngine;

    // 29,499.90 at 3% = 884.997 interest, plus 1% of principal = 294.999.
    expect($engine->suggestedMinimumPayment(29499.90, 3.0))->toBe(1180.0);
    expect($engine->suggestedMinimumPayment(10000.0, 1.5))->toBe(250.0);
});

test('a suggested minimum is always enough to make the balance fall', function () {
    $user = User::factory()->create();
    $engine = new PayoffEngine;

    foreach ([[29499.90, 3.0], [1000.0, 0.5], [250000.0, 4.25]] as [$balance, $rate]) {
        $debt = Debt::factory()->for($user)->create([
            'balance' => $balance,
            'monthly_rate' => $rate,
            'minimum_payment' => $engine->suggestedMinimumPayment($balance, $rate),
        ]);

        expect($engine->isGoingBackwards($debt))->toBeFalse();
        expect($engine->simulate(collect([$debt]), 'avalanche')['months'])->not->toBeNull();
    }
});

test('no minimum is suggested without a rate or a balance to work from', function () {
    $engine = new PayoffEngine;

    expect($engine->suggestedMinimumPayment(10000.0, null))->toBeNull();
    expect($engine->suggestedMinimumPayment(0.0, 3.0))->toBeNull();
});

test('the payments series is withheld unless a trajectory was asked for', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null]);

    expect((new PayoffEngine)->simulate(collect([$debt]), 'avalanche')['payments'])->toBeNull();
});

test('payments records what is handed over in each month, month one first', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 250, 'minimum_payment' => 100, 'monthly_rate' => null]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche', withTrajectory: true);

    // The trajectory carries an opening balance; payments start at month one.
    expect($result['trajectory'])->toBe([250.0, 150.0, 50.0, 0.0]);
    // The last month pays only what is left, not the full minimum.
    expect($result['payments'])->toBe([100.0, 100.0, 50.0]);
});

test('a cleared debt frees its minimum onto the next one without changing the monthly outlay', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 200, 'minimum_payment' => 100, 'monthly_rate' => null]);
    Debt::factory()->for($user)->create(['balance' => 900, 'minimum_payment' => 100, 'monthly_rate' => null]);

    $result = (new PayoffEngine)->simulate($user->debtFocusDebts()->get(), 'snowball', withTrajectory: true);

    // 200 a month goes out until the whole plan runs dry, whichever debt it lands on.
    expect(array_slice($result['payments'], 0, 5))->toBe([200.0, 200.0, 200.0, 200.0, 200.0]);
    expect(array_sum($result['payments']))->toBe(1100.0);
});

test('every peso paid goes to principal or interest', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 4000, 'minimum_payment' => 300, 'monthly_rate' => 1.5]);
    Debt::factory()->for($user)->create(['balance' => 1500, 'minimum_payment' => 120, 'monthly_rate' => 0.8]);

    $result = (new PayoffEngine)->simulate($user->debtFocusDebts()->get(), 'avalanche', 200.0, withTrajectory: true);

    $interest = array_sum(array_column($result['perDebt'], 'totalInterest'));

    expect(array_sum($result['payments']))->toBeGreaterThan(0.0);
    expect(array_sum($result['payments']))->toEqualWithDelta(5500.0 + $interest, 0.5);
});

test('a plan with nothing left to pay hands nothing over', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 0, 'minimum_payment' => 100, 'monthly_rate' => null]);

    expect((new PayoffEngine)->simulate($user->debtFocusDebts()->get(), 'avalanche', withTrajectory: true)['payments'])->toBe([]);
});
