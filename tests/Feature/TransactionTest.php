<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Goal;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

test('deleting a debt payment transaction restores the debt balance by its principal', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000, 'type' => 'cash']);
    $debt = Debt::factory()->for($user)->create([
        'balance' => 100000,
        'interest_rate' => 12,
        'interest_rate_period' => 'annual',
        'created_at' => Carbon::parse('2026-07-01'),
    ]);

    $this->actingAs($user)->post("/debts/{$debt->id}/payment", [
        'amount' => 5000,
        'account_id' => $account->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    $payment = DebtPayment::where('debt_id', $debt->id)->firstOrFail();
    $transaction = $payment->transaction;

    expect((float) $debt->fresh()->balance)->toBe(96019.18);

    $this->actingAs($user)->delete("/transactions/{$transaction->id}")->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(100000.0)
        ->and((float) $account->fresh()->balance)->toBe(100000.0)
        ->and(DebtPayment::find($payment->id))->toBeNull();
});

test('deleting a payment on a credit-linked debt restores the credit account balance', function (): void {
    $user = User::factory()->create();
    $paying = Account::factory()->for($user)->create(['balance' => 50000, 'type' => 'cash']);
    $card = Account::factory()->for($user)->create(['balance' => 20000, 'type' => 'credit']);
    $debt = Debt::factory()->for($user)->create([
        'account_id' => $card->id,
        'balance' => 20000,
        'interest_rate' => null,
    ]);

    $this->actingAs($user)->post("/debts/{$debt->id}/payment", [
        'amount' => 2000,
        'account_id' => $paying->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    expect((float) $card->fresh()->balance)->toBe(18000.0);

    $transaction = DebtPayment::where('debt_id', $debt->id)->firstOrFail()->transaction;

    $this->actingAs($user)->delete("/transactions/{$transaction->id}")->assertRedirect();

    expect((float) $card->fresh()->balance)->toBe(20000.0)
        ->and((float) $debt->fresh()->balance)->toBe(20000.0);
});

test('deleting a transaction with a legacy goal_id does not touch the goal saved amount', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000, 'type' => 'cash']);
    $goal = Goal::factory()->for($user)->create(['saved' => 2500]);
    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'type' => 'expense',
        'amount' => 100,
        'goal_id' => $goal->id,
    ]);

    $this->actingAs($user)->delete("/transactions/{$transaction->id}")->assertRedirect();

    expect((float) $goal->fresh()->saved)->toBe(2500.0)
        ->and(Transaction::find($transaction->id))->toBeNull();
});

test('updating a transaction with a legacy goal_id, without resending goal_id, does not touch the goal saved amount', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000, 'type' => 'cash']);
    $category = BudgetCategory::factory()->for($user)->create();
    $goal = Goal::factory()->for($user)->create(['saved' => 2500]);
    $transaction = Transaction::factory()->for($user)->for($account)->create([
        'type' => 'expense',
        'amount' => 100,
        'goal_id' => $goal->id,
    ]);

    $this->actingAs($user)->patch("/transactions/{$transaction->id}", [
        'type' => 'expense',
        'amount' => 150,
        'budget_category_id' => $category->id,
        'sinking_fund_id' => null,
        'merchant' => 'Updated Merchant',
        'account_id' => $account->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    expect((float) $goal->fresh()->saved)->toBe(2500.0)
        ->and((float) $transaction->fresh()->amount)->toBe(150.0);
});

test('store expense with sinking_fund_id does not require budget_category_id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 5000, 'type' => 'cash']);
    $fund = SinkingFund::factory()->for($user)->create(['saved' => 12000, 'target' => 12000]);

    $this->actingAs($user)
        ->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => 12000,
            'sinking_fund_id' => $fund->id,
            'account_id' => $account->id,
            'transacted_at' => Carbon::now()->toDateString(),
        ])
        ->assertRedirect();

    expect((float) $fund->fresh()->saved)->toBe(0.0);
    expect((float) $account->fresh()->balance)->toBe(-7000.0);
});

test('store expense with sinking_fund_id decrements fund saved (capped at zero)', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 5000]);
    $fund = SinkingFund::factory()->for($user)->create(['saved' => 500, 'target' => 1000]);

    $this->actingAs($user)
        ->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => 800,
            'sinking_fund_id' => $fund->id,
            'account_id' => $account->id,
            'transacted_at' => Carbon::now()->toDateString(),
        ])
        ->assertRedirect();

    expect((float) $fund->fresh()->saved)->toBe(-300.0);
});

test('destroy expense with sinking_fund_id restores fund saved', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 0]);
    $fund = SinkingFund::factory()->for($user)->create(['saved' => 8000]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'sinking_fund_id' => $fund->id,
        'type' => 'expense',
        'amount' => 8000,
        'transacted_at' => Carbon::now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->delete(route('transactions.destroy', $transaction))
        ->assertRedirect();

    expect((float) $fund->fresh()->saved)->toBe(16000.0);
});

test('store transaction requires amount, category, account, and date', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('transactions.store'), ['type' => 'expense'])
        ->assertSessionHasErrors(['amount', 'budget_category_id', 'account_id', 'transacted_at']);
});

test('store transaction creates record and decrements account balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 5000, 'type' => 'cash']);
    $category = BudgetCategory::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => 250.00,
            'budget_category_id' => $category->id,
            'merchant' => 'Jollibee',
            'account_id' => $account->id,
            'transacted_at' => Carbon::now()->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('transactions', [
        'user_id' => $user->id,
        'account_id' => $account->id,
        'budget_category_id' => $category->id,
        'merchant' => 'Jollibee',
    ]);

    $this->assertDatabaseHas('accounts', [
        'id' => $account->id,
        'balance' => 4750,
    ]);
});

test('store transaction rejects category belonging to another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $otherCategory = BudgetCategory::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => 100,
            'budget_category_id' => $otherCategory->id,
            'account_id' => $account->id,
            'transacted_at' => Carbon::now()->toDateString(),
        ])
        ->assertStatus(404);
});

test('store transaction rejects account belonging to another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $category = BudgetCategory::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('transactions.store'), [
            'type' => 'expense',
            'amount' => 100,
            'budget_category_id' => $category->id,
            'account_id' => $otherAccount->id,
            'transacted_at' => Carbon::now()->toDateString(),
        ])
        ->assertStatus(404);
});

test('store income transaction increments account balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 1000, 'type' => 'cash']);

    $this->actingAs($user)
        ->post(route('transactions.store'), [
            'type' => 'income',
            'amount' => 5000,
            'account_id' => $account->id,
            'transacted_at' => Carbon::now()->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('accounts', ['id' => $account->id, 'balance' => 6000]);
    $this->assertDatabaseHas('transactions', ['type' => 'income', 'amount' => 5000]);
});

test('store transfer decrements source and increments destination account', function () {
    $user = User::factory()->create();
    $from = Account::factory()->create(['user_id' => $user->id, 'balance' => 3000]);
    $to = Account::factory()->create(['user_id' => $user->id, 'balance' => 500]);

    $this->actingAs($user)
        ->post(route('transactions.store'), [
            'type' => 'transfer',
            'amount' => 1000,
            'account_id' => $from->id,
            'to_account_id' => $to->id,
            'transacted_at' => Carbon::now()->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('accounts', ['id' => $from->id, 'balance' => 2000]);
    $this->assertDatabaseHas('accounts', ['id' => $to->id, 'balance' => 1500]);
    $this->assertDatabaseHas('transactions', ['type' => 'transfer', 'to_account_id' => $to->id]);
});

test('store transfer requires different source and destination accounts', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('transactions.store'), [
            'type' => 'transfer',
            'amount' => 500,
            'account_id' => $account->id,
            'to_account_id' => $account->id,
            'transacted_at' => Carbon::now()->toDateString(),
        ])
        ->assertSessionHasErrors(['to_account_id']);
});

test('destroy transaction deletes record and restores account balance', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 3000, 'type' => 'cash']);
    $category = BudgetCategory::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'budget_category_id' => $category->id,
        'amount' => 500,
        'transacted_at' => Carbon::now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->delete(route('transactions.destroy', $transaction))
        ->assertRedirect();

    $this->assertDatabaseMissing('transactions', ['id' => $transaction->id]);
    $this->assertDatabaseHas('accounts', ['id' => $account->id, 'balance' => 3500]);
});

test('destroy transaction is forbidden for another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $other->id]);
    $category = BudgetCategory::factory()->create(['user_id' => $other->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $other->id,
        'account_id' => $account->id,
        'budget_category_id' => $category->id,
        'amount' => 100,
        'transacted_at' => Carbon::now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->delete(route('transactions.destroy', $transaction))
        ->assertStatus(404);
});
