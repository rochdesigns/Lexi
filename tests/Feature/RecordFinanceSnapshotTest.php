<?php

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\DebtPayment;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

test('finance:snapshot records one snapshot per account and debt for today', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 2500]);
    $savings = Account::factory()->for($user)->create(['balance' => 40000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 90000]);

    $this->artisan('finance:snapshot')->assertSuccessful();

    expect(AccountBalanceSnapshot::count())->toBe(2)
        ->and(DebtBalanceSnapshot::count())->toBe(1);

    $snapshot = AccountBalanceSnapshot::where('account_id', $checking->id)->firstOrFail();

    expect((float) $snapshot->balance)->toBe(2500.0)
        ->and($snapshot->snapshot_date->toDateString())->toBe('2026-08-05')
        ->and($snapshot->user_id)->toBe($user->id)
        ->and((float) AccountBalanceSnapshot::where('account_id', $savings->id)->value('balance'))->toBe(40000.0)
        ->and((float) DebtBalanceSnapshot::where('debt_id', $debt->id)->value('balance'))->toBe(90000.0);

    Carbon::setTestNow();
});

test('finance:snapshot honours the --date option and is idempotent for the same day', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);

    $this->artisan('finance:snapshot', ['--date' => '2026-08-01'])->assertSuccessful();

    $account->update(['balance' => 7777]);

    $this->artisan('finance:snapshot', ['--date' => '2026-08-01'])->assertSuccessful();

    expect(AccountBalanceSnapshot::count())->toBe(1)
        ->and((float) AccountBalanceSnapshot::first()->balance)->toBe(7777.0)
        ->and(AccountBalanceSnapshot::first()->snapshot_date->toDateString())->toBe('2026-08-01');

    Carbon::setTestNow();
});

test('finance:snapshot keeps each users records separate', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $alice = User::factory()->create();
    $bob = User::factory()->create();
    Account::factory()->for($alice)->create(['balance' => 100]);
    Account::factory()->for($bob)->create(['balance' => 200]);

    $this->artisan('finance:snapshot')->assertSuccessful();

    expect(AccountBalanceSnapshot::where('user_id', $alice->id)->count())->toBe(1)
        ->and(AccountBalanceSnapshot::where('user_id', $bob->id)->count())->toBe(1);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill reconstructs account balances by reversing transactions', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 5000]);

    Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'type' => 'expense',
        'amount' => 1000,
        'transacted_at' => '2026-08-05',
    ]);

    Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'type' => 'income',
        'amount' => 2000,
        'transacted_at' => '2026-08-04',
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])->assertSuccessful();

    $byDate = AccountBalanceSnapshot::where('account_id', $account->id)
        ->get()
        ->mapWithKeys(fn ($s) => [$s->snapshot_date->toDateString() => (float) $s->balance]);

    expect($byDate['2026-08-05'])->toBe(5000.0)
        ->and($byDate['2026-08-04'])->toBe(6000.0)
        ->and($byDate['2026-08-03'])->toBe(4000.0)
        ->and($byDate)->toHaveCount(3);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill leaves existing snapshots untouched', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 5000]);

    AccountBalanceSnapshot::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'balance' => 999,
        'snapshot_date' => '2026-08-04',
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])->assertSuccessful();

    expect((float) AccountBalanceSnapshot::whereDate('snapshot_date', '2026-08-04')->value('balance'))->toBe(999.0)
        ->and(AccountBalanceSnapshot::count())->toBe(3);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill reconstructs a debt that reconciles with its current balance', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create([
        'original' => 100000,
        'balance' => 90000,
        'interest_rate' => null,
        'created_at' => Carbon::parse('2026-06-05'),
    ]);

    DebtPayment::factory()->create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'account_id' => $account->id,
        'amount' => 10000,
        'principal_amount' => 10000,
        'interest_amount' => 0,
        'paid_at' => '2026-07-10',
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])->assertSuccessful();

    $byDate = DebtBalanceSnapshot::where('debt_id', $debt->id)
        ->get()
        ->mapWithKeys(fn ($s) => [$s->snapshot_date->toDateString() => (float) $s->balance]);

    expect($byDate)->toHaveCount(3)
        ->and($byDate['2026-08-05'])->toBe(90000.0)
        ->and($byDate['2026-08-03'])->toBe(90000.0);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill skips and warns about a debt that does not reconcile', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create([
        'name' => 'Mystery loan',
        'original' => 100000,
        'balance' => 12345,
        'interest_rate' => null,
        'created_at' => Carbon::parse('2026-06-05'),
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])
        ->expectsOutputToContain('Mystery loan')
        ->assertSuccessful();

    expect(DebtBalanceSnapshot::count())->toBe(0);

    Carbon::setTestNow();
});
