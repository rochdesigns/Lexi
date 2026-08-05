<?php

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TrajectoryReportService;
use Carbon\Carbon;

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

test('accounts store cost basis and emergency fund flag', function () {
    $user = User::factory()->create();

    $investment = Account::factory()->investment()->for($user)->create([
        'balance' => 120000,
        'cost_basis' => 100000,
    ])->fresh();

    $emergency = Account::factory()->emergencyFund()->for($user)->create()->fresh();

    expect((float) $investment->cost_basis)->toBe(100000.0)
        ->and($investment->type)->toBe('investment')
        ->and($investment->is_emergency_fund)->toBeFalse()
        ->and($emergency->is_emergency_fund)->toBeTrue()
        ->and($emergency->type)->toBe('cash');
});

test('a transaction can be linked to a debt and is unlinked when the debt is deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'debt_id' => $debt->id,
        'type' => 'expense',
        'amount' => 500,
    ]);

    expect($transaction->fresh()->debt->id)->toBe($debt->id)
        ->and($debt->transactions()->pluck('id')->all())->toBe([$transaction->id]);

    $debt->delete();

    expect($transaction->fresh()->debt_id)->toBeNull();
});

test('debt payments record the principal and interest split and cascade with their transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'debt_id' => $debt->id,
    ]);

    $payment = \App\Models\DebtPayment::create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'amount' => 5000,
        'principal_amount' => 4200,
        'interest_amount' => 800,
        'paid_at' => '2026-08-01',
    ]);

    expect((float) $payment->fresh()->principal_amount)->toBe(4200.0)
        ->and($payment->fresh()->paid_at->toDateString())->toBe('2026-08-01')
        ->and($debt->debtPayments()->count())->toBe(1)
        ->and($payment->transaction->id)->toBe($transaction->id);

    $transaction->delete();

    expect(\App\Models\DebtPayment::find($payment->id))->toBeNull();
});

test('balance snapshots are unique per owner per day', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = \App\Models\Debt::factory()->for($user)->create();

    \App\Models\AccountBalanceSnapshot::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'balance' => 1200.50,
        'snapshot_date' => '2026-08-01',
    ]);

    \App\Models\DebtBalanceSnapshot::create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'balance' => 90000,
        'snapshot_date' => '2026-08-01',
    ]);

    expect($account->balanceSnapshots()->count())->toBe(1)
        ->and((float) $account->balanceSnapshots()->first()->balance)->toBe(1200.50)
        ->and($debt->balanceSnapshots()->count())->toBe(1);

    expect(fn () => \App\Models\AccountBalanceSnapshot::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'balance' => 999,
        'snapshot_date' => '2026-08-01',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

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

    // Debt snapshots exist for June and August but NOT July — simulating a month
    // where RecordFinanceSnapshot's backfill declined to write debt history
    // because it didn't reconcile within tolerance (Task 9). July's debt balance
    // is unknown, not zero, so July must be dropped from the trend rather than
    // computed as if the debt didn't exist that month.
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
