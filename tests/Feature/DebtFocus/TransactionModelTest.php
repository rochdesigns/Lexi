<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Transaction;
use App\Models\User;

test('an expense transaction persists against a single account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->create([
        'type' => 'expense',
        'account_id' => $account->id,
        'from_account_id' => null,
        'to_account_id' => null,
        'amount' => 42.50,
        'description' => 'Groceries',
        'occurred_at' => '2026-08-28',
    ]);

    $fresh = $transaction->fresh();

    expect($fresh->type)->toBe('expense');
    expect($fresh->account_id)->toBe($account->id);
    expect($fresh->from_account_id)->toBeNull();
    expect($fresh->to_account_id)->toBeNull();
    expect((float) $fresh->amount)->toBe(42.50);
    expect($fresh->description)->toBe('Groceries');
    expect($fresh->occurred_at->toDateString())->toBe('2026-08-28');
    expect($fresh->account->id)->toBe($account->id);
});

test('a transfer transaction persists with both legs and no single account_id', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->create([
        'type' => 'transfer',
        'account_id' => null,
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount' => 100,
    ]);

    $fresh = $transaction->fresh();

    expect($fresh->type)->toBe('transfer');
    expect($fresh->account_id)->toBeNull();
    expect($fresh->fromAccount->id)->toBe($from->id);
    expect($fresh->toAccount->id)->toBe($to->id);
});

test('description is nullable', function () {
    $transaction = Transaction::factory()->create(['description' => null]);

    expect($transaction->fresh()->description)->toBeNull();
});
