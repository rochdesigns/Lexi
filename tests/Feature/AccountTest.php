<?php

use App\Models\Account;
use App\Models\Debt;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

test('guest is redirected from accounts index', function () {
    $this->get(route('accounts.index'))->assertRedirect(route('login'));
});

test('accounts index returns grouped accounts and net worth', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'cash', 'balance' => 2000]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => -500]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'investment', 'balance' => 5000]);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Accounts')
            ->where('netWorth', 6500)
            ->has('groups', 3)
        );
});

test('net worth is correct sum including negative credit balances', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'cash', 'balance' => 10000]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => -3000]);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page->where('netWorth', 7000));
});

test('store creates account for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('accounts.store'), [
            'name' => 'BDO Checking',
            'institution' => 'BDO',
            'last4' => '1234',
            'balance' => 15000,
            'type' => 'cash',
        ])
        ->assertRedirect(route('accounts.index'));

    expect(Account::where('user_id', $user->id)->where('name', 'BDO Checking')->exists())->toBeTrue();
});

test('store validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('accounts.store'), [])
        ->assertSessionHasErrors(['name', 'balance', 'type']);
});

test('store rejects invalid account type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('accounts.store'), ['name' => 'Test', 'balance' => 100, 'type' => 'crypto'])
        ->assertSessionHasErrors(['type']);
});

test('update modifies account fields', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 1000]);

    $this->actingAs($user)
        ->patch(route('accounts.update', $account), [
            'name' => $account->name,
            'institution' => $account->institution,
            'last4' => $account->last4,
            'balance' => 2500,
            'type' => $account->type,
        ])
        ->assertRedirect();

    expect((float) $account->fresh()->balance)->toEqual(2500.0);
});

test('update returns 403 for another users account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->patch(route('accounts.update', $account), [
            'name' => 'Hacked',
            'balance' => 0,
            'type' => 'cash',
        ])
        ->assertForbidden();
});

test('destroy deletes account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('accounts.destroy', $account))
        ->assertRedirect();

    expect(Account::find($account->id))->toBeNull();
});

test('destroy returns 403 for another users account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->delete(route('accounts.destroy', $account))
        ->assertForbidden();
});

test('transactions endpoint returns transactions and categories for the account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $other = Account::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 100, 'merchant' => 'Jollibee']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 500]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $other->id, 'to_account_id' => $account->id, 'type' => 'transfer', 'amount' => 200]);

    $response = $this->actingAs($user)
        ->getJson(route('accounts.transactions', $account))
        ->assertOk()
        ->assertJsonStructure(['transactions', 'categories'])
        ->assertJsonCount(3, 'transactions');

    $response->assertJsonFragment(['merchant' => 'Jollibee', 'type' => 'expense']);
    $response->assertJsonFragment(['type' => 'income', 'is_destination' => false]);
    $response->assertJsonFragment(['type' => 'transfer', 'is_destination' => true]);
});

test('transactions endpoint excludes transactions from other accounts', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $other = Account::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $other->id]);

    $this->actingAs($user)
        ->getJson(route('accounts.transactions', $account))
        ->assertOk()
        ->assertJsonCount(1, 'transactions');
});

test('transactions endpoint returns 403 for another users account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->getJson(route('accounts.transactions', $account))
        ->assertForbidden();
});

test('transactions endpoint redirects guests', function () {
    $account = Account::factory()->create();

    $this->get(route('accounts.transactions', $account))
        ->assertRedirect(route('login'));
});

test('accountTotal excludes credit accounts that have a linked debt', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);

    $cash = Account::factory()->create(['user_id' => $user->id, 'type' => 'cash', 'balance' => 10000]);
    $cc = Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => 3000]);
    Debt::factory()->create(['user_id' => $user->id, 'account_id' => $cc->id, 'balance' => 3000]);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('accountTotal', 10000) // CC excluded because it has a linked debt
            ->where('debtTotal', 3000)
            ->where('netWorth', 7000)
        );
});

test('accountTotal includes unlinked credit accounts (backward compat)', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);

    Account::factory()->create(['user_id' => $user->id, 'type' => 'cash', 'balance' => 10000]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => 500]);
    // No debt linked to the credit account

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('accountTotal', 10500) // unlinked credit still included
        );
});

test('accounts index includes a cycle label with year for a non-calendar-month cycle', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    Setting::factory()->create([
        'user_id' => $user->id,
        'budget_cycle_days' => 31,
        'budget_cycle_anchor' => '2026-06-24',
    ]);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page->where('cycleLabel', 'Jul 25 – Aug 24, 2026'));

    Carbon::setTestNow();
});

test('updating credit account balance syncs linked debt', function () {
    $user = User::factory()->create();
    $cc = Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => 5000]);
    $debt = Debt::factory()->create([
        'user_id' => $user->id,
        'account_id' => $cc->id,
        'balance' => 5000,
        'minimum_payment_rate' => 2.00,
        'per_month' => 100,
    ]);

    $this->actingAs($user)
        ->patch(route('accounts.update', $cc), [
            'name' => $cc->name,
            'balance' => 8000,
            'type' => 'credit',
        ])->assertRedirect();

    $debt->refresh();
    expect((float) $debt->balance)->toBe(8000.0);
    expect((float) $debt->per_month)->toBe(160.0); // 8000 * 2% = 160
});
