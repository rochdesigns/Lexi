<?php

use App\Models\Account;
use App\Models\Setting;
use App\Models\User;

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
