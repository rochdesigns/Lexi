<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Models\User;

test('a debt focus debt persists with the expected fields and casts', function () {
    $user = User::factory()->create();

    $debt = Debt::factory()->for($user)->create([
        'name' => 'Visa',
        'balance' => 1500.50,
        'monthly_rate' => 1.99,
        'minimum_payment' => 45,
        'debt_type' => 'revolving',
    ]);

    $fresh = $debt->fresh();

    expect($fresh->name)->toBe('Visa');
    expect((float) $fresh->balance)->toBe(1500.50);
    expect((float) $fresh->monthly_rate)->toBe(1.99);
    expect((float) $fresh->minimum_payment)->toBe(45.0);
    expect($fresh->debt_type)->toBe('revolving');
    expect($fresh->user_id)->toBe($user->id);
});

test('monthly_rate is nullable', function () {
    $user = User::factory()->create();

    $debt = Debt::factory()->for($user)->create(['monthly_rate' => null]);

    expect($debt->fresh()->monthly_rate)->toBeNull();
});

test('debt_type defaults to installment at the database level when not supplied', function () {
    $user = User::factory()->create();

    $debt = Debt::create([
        'user_id' => $user->id,
        'name' => 'Unspecified Debt',
        'balance' => 100,
        'minimum_payment' => 10,
    ]);

    expect($debt->fresh()->debt_type)->toBe('installment');
});

test('a debt can be linked to an account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create();

    $debt = Debt::factory()->for($user)->create([
        'debt_type' => 'revolving',
        'linked_account_id' => $account->id,
    ]);

    $fresh = $debt->fresh();

    expect($fresh->linked_account_id)->toBe($account->id);
    expect($fresh->linkedAccount)->toBeInstanceOf(Account::class);
    expect($fresh->linkedAccount->id)->toBe($account->id);
});

test('linked_account_id defaults to null when not supplied', function () {
    $user = User::factory()->create();

    $debt = Debt::factory()->for($user)->create();

    expect($debt->fresh()->linked_account_id)->toBeNull();
    expect($debt->fresh()->linkedAccount)->toBeNull();
});

test('linked_account_id is set to null when the linked account is deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create();
    $debt = Debt::factory()->for($user)->create(['linked_account_id' => $account->id]);

    $account->delete();

    expect($debt->fresh()->linked_account_id)->toBeNull();
});
