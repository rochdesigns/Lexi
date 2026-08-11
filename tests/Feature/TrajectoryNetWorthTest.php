<?php

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\User;
use App\Services\TrajectoryReportService;
use Carbon\Carbon;

test('net worth reports the current figure and a monthly snapshot trend', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);
    $debt = Debt::factory()->for($user)->create(['balance' => 4000]);

    foreach ([['2026-07-31', 8000], ['2026-08-05', 10000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    foreach ([['2026-07-31', 5000], ['2026-08-05', 4000]] as [$date, $balance]) {
        DebtBalanceSnapshot::create([
            'user_id' => $user->id, 'debt_id' => $debt->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeTrue()
        ->and($result['current'])->toBe(6000.0)
        ->and($result['accountTotal'])->toBe(10000.0)
        ->and($result['debtTotal'])->toBe(4000.0)
        ->and($result['trend'])->toHaveCount(2)
        ->and($result['trend'][0])->toBe(['month' => '2026-07', 'label' => 'Jul 2026', 'value' => 3000.0])
        ->and($result['trend'][1]['value'])->toBe(6000.0)
        ->and($result['changeAmount'])->toBe(3000.0)
        ->and($result['changePct'])->toBe(100.0);

    Carbon::setTestNow();
});

test('net worth excludes credit accounts that are linked to a debt', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);
    $card = Account::factory()->for($user)->create(['balance' => 3000, 'type' => 'credit']);
    Debt::factory()->for($user)->create(['account_id' => $card->id, 'balance' => 3000]);

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['accountTotal'])->toBe(10000.0)
        ->and($result['current'])->toBe(7000.0);

    Carbon::setTestNow();
});

test('net worth is unavailable without at least two months of snapshots but still reports the headline', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);

    AccountBalanceSnapshot::create([
        'user_id' => $user->id, 'account_id' => $account->id,
        'balance' => 10000, 'snapshot_date' => '2026-08-05',
    ]);

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString()
        ->and($result['current'])->toBe(10000.0)
        ->and($result['trend'])->toBe([]);

    Carbon::setTestNow();
});

test('net worth omits a month from the trend when the user has debts but no debt snapshot for that month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 7000, 'type' => 'cash']);
    $debt = Debt::factory()->for($user)->create(['balance' => 1500]);

    foreach ([['2026-06-30', 5000], ['2026-07-31', 6000], ['2026-08-05', 7000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    // Debt snapshots exist for June and August but NOT July — e.g. the daily
    // command never ran that month. July's debt balance is unknown, not zero, so
    // July must be dropped from the trend rather than computed as if the debt
    // didn't exist that month.
    foreach ([['2026-06-30', 1000], ['2026-08-05', 1500]] as [$date, $balance]) {
        DebtBalanceSnapshot::create([
            'user_id' => $user->id, 'debt_id' => $debt->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeTrue()
        ->and($result['trend'])->toHaveCount(2)
        ->and(collect($result['trend'])->pluck('month')->all())->toBe(['2026-06', '2026-08'])
        ->and($result['trend'][0]['value'])->toBe(4000.0)
        ->and($result['trend'][1]['value'])->toBe(5500.0);

    Carbon::setTestNow();
});

test('net worth omits a month where only some of the users debts were snapshotted', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 7000, 'type' => 'cash']);
    $card = Debt::factory()->for($user)->create(['balance' => 1500]);
    $carLoan = Debt::factory()->for($user)->create(['balance' => 2000]);

    foreach ([['2026-06-30', 5000], ['2026-07-31', 6000], ['2026-08-05', 7000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    // Both debts reported in June and August; only the card reported in July.
    // Counting July would silently value the car loan at ₱0 and overstate net worth.
    foreach ([['2026-06-30', 1000], ['2026-07-31', 1200], ['2026-08-05', 1500]] as [$date, $balance]) {
        DebtBalanceSnapshot::create([
            'user_id' => $user->id, 'debt_id' => $card->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    foreach ([['2026-06-30', 2500], ['2026-08-05', 2000]] as [$date, $balance]) {
        DebtBalanceSnapshot::create([
            'user_id' => $user->id, 'debt_id' => $carLoan->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeTrue()
        ->and(collect($result['trend'])->pluck('month')->all())->toBe(['2026-06', '2026-08'])
        ->and($result['trend'][0]['value'])->toBe(1500.0)
        ->and($result['trend'][1]['value'])->toBe(3500.0);

    Carbon::setTestNow();
});

test('net worth reports a null changePct when the earliest trend month nets to exactly zero', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);
    $debt = Debt::factory()->for($user)->create(['balance' => 4000]);

    // June's account and debt balances match exactly (5000 - 5000 = 0), so the
    // earliest trend entry nets to 0.0 and would divide by zero if not guarded.
    foreach ([['2026-06-30', 5000], ['2026-08-05', 10000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    foreach ([['2026-06-30', 5000], ['2026-08-05', 4000]] as [$date, $balance]) {
        DebtBalanceSnapshot::create([
            'user_id' => $user->id, 'debt_id' => $debt->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeTrue()
        ->and($result['trend'])->toHaveCount(2)
        ->and($result['trend'][0])->toBe(['month' => '2026-06', 'label' => 'Jun 2026', 'value' => 0.0])
        ->and($result['trend'][1]['value'])->toBe(6000.0)
        ->and($result['changeAmount'])->toBe(6000.0)
        ->and($result['changePct'])->toBeNull();

    Carbon::setTestNow();
});

test('net worth omits a month where only some of the users accounts were snapshotted', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 7000, 'type' => 'cash']);
    $savings = Account::factory()->for($user)->create(['balance' => 20000, 'type' => 'cash']);

    foreach ([['2026-06-30', 5000], ['2026-07-31', 6000], ['2026-08-05', 7000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $checking->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    // Savings missed July, so July's account total would be understated by ₱18,000.
    foreach ([['2026-06-30', 18000], ['2026-08-05', 20000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $savings->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeTrue()
        ->and(collect($result['trend'])->pluck('month')->all())->toBe(['2026-06', '2026-08'])
        ->and($result['trend'][0]['value'])->toBe(23000.0)
        ->and($result['trend'][1]['value'])->toBe(27000.0);

    Carbon::setTestNow();
});
