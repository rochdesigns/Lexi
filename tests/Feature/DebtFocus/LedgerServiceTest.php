<?php

use App\Models\DebtFocus\Account;
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
