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
    $account = Account::factory()->for($user)->create(['balance' => 5000, 'type' => 'cash']);

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

/** @return array<string, float> */
function debtBalancesByDate(int $debtId): array
{
    return DebtBalanceSnapshot::where('debt_id', $debtId)
        ->get()
        ->mapWithKeys(fn ($s) => [$s->snapshot_date->toDateString() => (float) $s->balance])
        ->all();
}

test('finance:snapshot --backfill walks a debt backward from its live balance, reversing principal only', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => 'cash']);
    $debt = Debt::factory()->for($user)->create([
        'original' => 100000,
        'balance' => 90000,
        'interest_rate' => 18,
        'interest_rate_period' => 'annual',
    ]);

    // A ₱10,000 payment of which only ₱8,000 reduced what is owed.
    DebtPayment::factory()->create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'account_id' => $account->id,
        'amount' => 10000,
        'principal_amount' => 8000,
        'interest_amount' => 2000,
        'paid_at' => '2026-08-04',
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])->assertSuccessful();

    $byDate = debtBalancesByDate($debt->id);

    // Before the payment the debt stood at 90,000 + 8,000 principal — not + 10,000,
    // because the ₱2,000 interest slice never reduced the balance.
    expect($byDate)->toHaveCount(3)
        ->and($byDate['2026-08-05'])->toBe(90000.0)
        ->and($byDate['2026-08-04'])->toBe(90000.0)
        ->and($byDate['2026-08-03'])->toBe(98000.0);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill records a debt whose original no longer explains its balance', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create([
        'name' => 'Mystery loan',
        'original' => 100000,
        'balance' => 12345,
        'interest_rate' => null,
        'created_at' => Carbon::parse('2026-06-05'),
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])
        ->doesntExpectOutputToContain('Mystery loan')
        ->assertSuccessful();

    // No payment history at all, so the balance is flat at its known-true value
    // rather than being skipped for failing to reconcile against `original`.
    expect(debtBalancesByDate($debt->id))->toBe([
        '2026-08-03' => 12345.0,
        '2026-08-04' => 12345.0,
        '2026-08-05' => 12345.0,
    ]);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill reconstructs several months of an interest-bearing debt', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['type' => 'cash']);
    $debt = Debt::factory()->for($user)->create([
        'original' => 120000,
        'balance' => 85000,
        'interest_rate' => 18,
        'interest_rate_period' => 'annual',
    ]);

    $payments = [
        ['paid_at' => '2026-06-01', 'principal_amount' => 3700, 'interest_amount' => 1300],
        ['paid_at' => '2026-07-01', 'principal_amount' => 3750, 'interest_amount' => 1250],
        ['paid_at' => '2026-08-01', 'principal_amount' => 3800, 'interest_amount' => 1200],
    ];

    foreach ($payments as $payment) {
        DebtPayment::factory()->create([
            'user_id' => $user->id,
            'debt_id' => $debt->id,
            'account_id' => $account->id,
            'amount' => 5000,
            ...$payment,
        ]);
    }

    $this->artisan('finance:snapshot', ['--backfill' => 70])->assertSuccessful();

    $byDate = debtBalancesByDate($debt->id);

    // Walking backward: 85,000 today; +3,800 before the Aug 1 payment;
    // +3,750 before Jul 1; +3,700 before Jun 1.
    expect($byDate)->toHaveCount(70)
        ->and($byDate['2026-08-05'])->toBe(85000.0)
        ->and($byDate['2026-08-01'])->toBe(85000.0)
        ->and($byDate['2026-07-31'])->toBe(88800.0)
        ->and($byDate['2026-07-01'])->toBe(88800.0)
        ->and($byDate['2026-06-30'])->toBe(92550.0)
        ->and($byDate['2026-06-01'])->toBe(92550.0)
        ->and($byDate['2026-05-31'])->toBe(96250.0)
        ->and($byDate['2026-05-28'])->toBe(96250.0);

    // The series is monotonically non-increasing over time and never touches `original`.
    $values = array_values($byDate);
    ksort($byDate);

    expect(max($values))->toBe(96250.0)
        ->and(min($values))->toBe(85000.0);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill mirrors a card-linked debt onto its credit account history', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $card = Account::factory()->for($user)->create(['type' => 'credit', 'balance' => 30000]);
    $cash = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 50000]);
    $debt = Debt::factory()->for($user)->create(['account_id' => $card->id, 'balance' => 30000]);

    // A ₱5,000 purchase charged to the card raises what is owed.
    Transaction::factory()->for($user)->create([
        'account_id' => $card->id,
        'type' => 'expense',
        'amount' => 5000,
        'transacted_at' => '2026-08-04',
    ]);

    // A ₱4,000 payment from cash lowers the card balance without a row pointing at the card.
    $payment = Transaction::factory()->for($user)->create([
        'account_id' => $cash->id,
        'debt_id' => $debt->id,
        'type' => 'expense',
        'amount' => 4000,
        'transacted_at' => '2026-08-03',
    ]);

    DebtPayment::factory()->create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'transaction_id' => $payment->id,
        'account_id' => $cash->id,
        'amount' => 4000,
        'principal_amount' => 4000,
        'interest_amount' => 0,
        'paid_at' => '2026-08-03',
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 4])->assertSuccessful();

    // Forward reading: 29,000 owed, pay 4,000 on the 3rd, charge 5,000 on the 4th → 30,000.
    expect(debtBalancesByDate($debt->id))->toBe([
        '2026-08-02' => 29000.0,
        '2026-08-03' => 25000.0,
        '2026-08-04' => 30000.0,
        '2026-08-05' => 30000.0,
    ]);

    // The credit account it mirrors reconstructs identically.
    $cardByDate = AccountBalanceSnapshot::where('account_id', $card->id)
        ->get()
        ->mapWithKeys(fn ($s) => [$s->snapshot_date->toDateString() => (float) $s->balance])
        ->all();

    expect($cardByDate)->toBe(debtBalancesByDate($debt->id));

    Carbon::setTestNow();
});

test('finance:snapshot --backfill treats a credit account balance as money owed', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $card = Account::factory()->for($user)->create(['type' => 'credit', 'balance' => 8000]);

    Transaction::factory()->for($user)->create([
        'account_id' => $card->id,
        'type' => 'expense',
        'amount' => 2000,
        'transacted_at' => '2026-08-04',
    ]);

    Transaction::factory()->for($user)->create([
        'account_id' => $card->id,
        'type' => 'income',
        'amount' => 3000,
        'transacted_at' => '2026-08-03',
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 4])->assertSuccessful();

    $byDate = AccountBalanceSnapshot::where('account_id', $card->id)
        ->get()
        ->mapWithKeys(fn ($s) => [$s->snapshot_date->toDateString() => (float) $s->balance])
        ->all();

    // Forward reading: 9,000 owed, a 3,000 payment on the 3rd, a 2,000 charge on the 4th.
    expect($byDate)->toBe([
        '2026-08-02' => 9000.0,
        '2026-08-03' => 6000.0,
        '2026-08-04' => 8000.0,
        '2026-08-05' => 8000.0,
    ]);

    Carbon::setTestNow();
});
