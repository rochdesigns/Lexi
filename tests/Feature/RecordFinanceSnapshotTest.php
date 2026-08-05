<?php

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
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
