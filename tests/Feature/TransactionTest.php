<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\Goal;
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
