<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->category = BudgetCategory::factory()->for($this->user)->create();
});

test('expense on credit account increases balance (owe more)', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 5000]);

    $this->post('/transactions', [
        'type' => 'expense',
        'amount' => 1500,
        'merchant' => 'Jollibee',
        'account_id' => $cc->id,
        'budget_category_id' => $this->category->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    expect((float) $cc->fresh()->balance)->toBe(6500.0);
});

test('income on credit account decreases balance (owe less)', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 5000]);

    $this->post('/transactions', [
        'type' => 'income',
        'amount' => 500,
        'merchant' => 'Cashback',
        'account_id' => $cc->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    expect((float) $cc->fresh()->balance)->toBe(4500.0);
});

test('expense on credit account syncs linked debt', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 5000]);
    $debt = Debt::factory()->for($this->user)->create([
        'account_id' => $cc->id,
        'balance' => 5000,
        'minimum_payment_rate' => 3.00,
    ]);

    $this->post('/transactions', [
        'type' => 'expense',
        'amount' => 2000,
        'merchant' => 'SM',
        'account_id' => $cc->id,
        'budget_category_id' => $this->category->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    $debt->refresh();
    expect((float) $debt->balance)->toBe(7000.0);
    expect((float) $debt->per_month)->toBe(210.0); // 7000 * 3% = 210
});

test('deleting credit account expense reverses balance correctly', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 6500]);
    $tx = $this->user->transactions()->create([
        'type' => 'expense',
        'amount' => 1500,
        'merchant' => 'Jollibee',
        'account_id' => $cc->id,
        'budget_category_id' => $this->category->id,
        'transacted_at' => '2026-08-01',
    ]);

    $this->delete("/transactions/{$tx->id}")->assertRedirect();

    expect((float) $cc->fresh()->balance)->toBe(5000.0); // 6500 - 1500
});
