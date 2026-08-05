<?php

use App\Models\Account;
use App\Models\RecurringBill;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('paying a bill creates a transaction and sets last_paid_at', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id, 'balance' => 10000]);
    $bill = RecurringBill::factory()->create(['user_id' => $this->user->id, 'amount' => 599, 'day_of_month' => 12]);

    $this->post("/recurring-bills/{$bill->id}/pay", [
        'amount' => 599,
        'merchant' => $bill->name,
        'account_id' => $account->id,
        'budget_category_id' => null,
        'transacted_at' => '2026-07-30',
    ])->assertRedirect();

    expect(Transaction::where('account_id', $account->id)->where('merchant', $bill->name)->exists())->toBeTrue();
    expect($account->fresh()->balance)->toBe('9401.00');
    expect($bill->fresh()->last_paid_at->toDateString())->toBe('2026-07-30');
});

test('paying a bill marks last_paid_at so it can be filtered from upcoming', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id, 'balance' => 10000]);
    $bill = RecurringBill::factory()->create(['user_id' => $this->user->id, 'amount' => 599, 'day_of_month' => 12]);

    expect($bill->last_paid_at)->toBeNull();

    $this->post("/recurring-bills/{$bill->id}/pay", [
        'amount' => 599,
        'merchant' => $bill->name,
        'account_id' => $account->id,
        'budget_category_id' => null,
        'transacted_at' => '2026-07-30',
    ]);

    expect($bill->fresh()->last_paid_at)->not->toBeNull();
});

test('cannot pay another user bill', function () {
    $otherUser = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $this->user->id, 'balance' => 10000]);
    $bill = RecurringBill::factory()->create(['user_id' => $otherUser->id, 'amount' => 100, 'day_of_month' => 1]);

    $this->post("/recurring-bills/{$bill->id}/pay", [
        'amount' => 100,
        'merchant' => $bill->name,
        'account_id' => $account->id,
        'budget_category_id' => null,
        'transacted_at' => '2026-07-30',
    ])->assertForbidden();
});
