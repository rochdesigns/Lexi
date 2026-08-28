<?php

use App\Models\DebtFocus\Account;
use App\Models\User;
use App\Services\DebtFocus\LedgerService;

test('income increases an asset account balance and records the transaction', function () {
    $account = Account::factory()->create(['balance' => 100]);

    $transaction = (new LedgerService)->recordIncome($account, 50, 'Paycheck');

    expect((float) $account->fresh()->balance)->toBe(150.0);
    expect($transaction->type)->toBe('income');
    expect($transaction->account_id)->toBe($account->id);
    expect((float) $transaction->amount)->toBe(50.0);
    expect($transaction->description)->toBe('Paycheck');
    expect($transaction->user_id)->toBe($account->user_id);
});

test('income decreases a liability account balance', function () {
    $card = Account::factory()->liability()->create(['balance' => 100]);

    (new LedgerService)->recordIncome($card, 30, 'Refund');

    expect((float) $card->fresh()->balance)->toBe(70.0);
});

test('expense decreases an asset account balance and records the transaction', function () {
    $account = Account::factory()->create(['balance' => 100]);

    $transaction = (new LedgerService)->recordExpense($account, 30, 'Groceries');

    expect((float) $account->fresh()->balance)->toBe(70.0);
    expect($transaction->type)->toBe('expense');
    expect($transaction->account_id)->toBe($account->id);
    expect((float) $transaction->amount)->toBe(30.0);
});

test('expense increases a liability account balance, since a purchase increases what is owed', function () {
    $card = Account::factory()->liability()->create(['balance' => 100]);

    (new LedgerService)->recordExpense($card, 30, 'New shoes');

    expect((float) $card->fresh()->balance)->toBe(130.0);
});

test('recordIncome and recordExpense default occurred_at to today', function () {
    $account = Account::factory()->create();

    $transaction = (new LedgerService)->recordIncome($account, 10);

    expect($transaction->occurred_at->toDateString())->toBe(now()->toDateString());
});

test('a transfer between two asset accounts moves the balance from one to the other', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 500]);
    $savings = Account::factory()->for($user)->create(['balance' => 200]);

    $transaction = (new LedgerService)->recordTransfer($checking, $savings, 100, 'Move to savings');

    expect((float) $checking->fresh()->balance)->toBe(400.0);
    expect((float) $savings->fresh()->balance)->toBe(300.0);
    expect($transaction->type)->toBe('transfer');
    expect($transaction->from_account_id)->toBe($checking->id);
    expect($transaction->to_account_id)->toBe($savings->id);
    expect($transaction->account_id)->toBeNull();
    expect($transaction->user_id)->toBe($checking->user_id);
});

test('a transfer to a liability account pays it down, the credit-card-payment case', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 500]);
    $card = Account::factory()->for($user)->liability()->create(['balance' => 300]);

    (new LedgerService)->recordTransfer($checking, $card, 100, 'Card payment');

    expect((float) $checking->fresh()->balance)->toBe(400.0);
    expect((float) $card->fresh()->balance)->toBe(200.0);
});

test('recordTransfer throws when the two accounts belong to different users', function () {
    $from = Account::factory()->create();
    $to = Account::factory()->create();

    expect(fn () => (new LedgerService)->recordTransfer($from, $to, 50))
        ->toThrow(InvalidArgumentException::class);
});

test('recordTransfer does not mutate either balance when accounts belong to different users', function () {
    $from = Account::factory()->create(['balance' => 500]);
    $to = Account::factory()->create(['balance' => 200]);

    try {
        (new LedgerService)->recordTransfer($from, $to, 50);
    } catch (InvalidArgumentException) {
        // expected
    }

    expect((float) $from->fresh()->balance)->toBe(500.0);
    expect((float) $to->fresh()->balance)->toBe(200.0);
});
