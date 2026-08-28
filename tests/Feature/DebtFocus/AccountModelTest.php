<?php

use App\Models\DebtFocus\Account;
use App\Models\User;

test('an asset account persists with its fields', function () {
    $user = User::factory()->create();

    $account = Account::factory()->for($user)->create([
        'name' => 'Main Checking',
        'institution' => 'BPI',
        'type' => 'checking',
        'is_liability' => false,
        'balance' => 1500.50,
    ]);

    $fresh = $account->fresh();

    expect($fresh->name)->toBe('Main Checking');
    expect($fresh->institution)->toBe('BPI');
    expect($fresh->type)->toBe('checking');
    expect($fresh->is_liability)->toBeFalse();
    expect((float) $fresh->balance)->toBe(1500.50);
    expect($fresh->user_id)->toBe($user->id);
});

test('the liability factory state produces a credit account with is_liability true', function () {
    $account = Account::factory()->liability()->create();

    expect($account->type)->toBe('credit');
    expect($account->is_liability)->toBeTrue();
});

test('institution is nullable', function () {
    $account = Account::factory()->create(['institution' => null]);

    expect($account->fresh()->institution)->toBeNull();
});

test('a user has many debt focus accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->count(2)->create();
    Account::factory()->create();

    expect($user->debtFocusAccounts)->toHaveCount(2);
});
