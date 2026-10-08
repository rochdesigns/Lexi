<?php

use App\Models\Money\Account;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionCategory;
use App\Models\Money\TransactionKind;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['balance' => 100_000]);
});

test('reconciling to a lower balance records a decrease and matches the bank', function (): void {
    $this->actingAs($this->user)
        ->post("/money/accounts/{$this->account->id}/reconcile", ['balance' => 800])
        ->assertRedirect();

    $adjustment = $this->user->transactions()->sole();
    expect($adjustment->kind)->toBe(TransactionKind::AdjustmentDecrease)
        ->and($adjustment->category)->toBe(TransactionCategory::Adjustment)
        ->and($adjustment->amount)->toBe(20_000)
        ->and($adjustment->account_id)->toBe($this->account->id)
        ->and($this->account->fresh()->balance)->toBe(80_000);
});

test('reconciling to a higher balance records an increase', function (): void {
    $this->actingAs($this->user)
        ->post("/money/accounts/{$this->account->id}/reconcile", ['balance' => 1250.50])
        ->assertRedirect();

    $adjustment = $this->user->transactions()->sole();
    expect($adjustment->kind)->toBe(TransactionKind::AdjustmentIncrease)
        ->and($adjustment->amount)->toBe(25_050)
        ->and($this->account->fresh()->balance)->toBe(125_050);
});

test('reconciling an account that already matches records nothing', function (): void {
    $this->actingAs($this->user)
        ->post("/money/accounts/{$this->account->id}/reconcile", ['balance' => 1000])
        ->assertRedirect();

    expect(Transaction::count())->toBe(0)->and($this->account->fresh()->balance)->toBe(100_000);
});

test('deleting an adjustment puts the balance back', function (): void {
    $this->actingAs($this->user)->post("/money/accounts/{$this->account->id}/reconcile", ['balance' => 800]);
    $adjustment = $this->user->transactions()->sole();

    $this->actingAs($this->user)->delete("/money/transactions/{$adjustment->id}")->assertRedirect();

    expect($this->account->fresh()->balance)->toBe(100_000);
});

test('adjustments are not counted as spending or income', function (): void {
    $this->actingAs($this->user)->post("/money/accounts/{$this->account->id}/reconcile", ['balance' => 800]);
    $this->actingAs($this->user)->post("/money/accounts/{$this->account->id}/reconcile", ['balance' => 5000]);

    $this->actingAs($this->user)
        ->get('/money/transactions')
        ->assertInertia(fn ($page) => $page
            ->where('total', 0)
            ->where('received', 0)
            ->where('categoryTotals', [])
            ->has('transactions', 2));
});

test('the balance must be a number that is not negative', function (): void {
    $this->actingAs($this->user)->post("/money/accounts/{$this->account->id}/reconcile", ['balance' => -1])->assertSessionHasErrors('balance');
    $this->actingAs($this->user)->post("/money/accounts/{$this->account->id}/reconcile", [])->assertSessionHasErrors('balance');
});

test('I cannot reconcile someone else\'s account', function (): void {
    $other = Account::factory()->create();

    $this->actingAs($this->user)->post("/money/accounts/{$other->id}/reconcile", ['balance' => 1])->assertForbidden();
});

test('guests cannot reconcile', function (): void {
    $this->post("/money/accounts/{$this->account->id}/reconcile", ['balance' => 1])->assertRedirect('/login');
});
