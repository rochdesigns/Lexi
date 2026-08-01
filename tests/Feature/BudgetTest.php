<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Setting;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

test('guest is redirected from budgets page', function () {
    $this->get(route('budgets'))->assertRedirect(route('login'));
});

test('budgets page renders with Inertia component', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->get(route('budgets'))
        ->assertInertia(fn ($page) => $page
            ->component('Budgets')
            ->has('categories')
            ->has('transactions')
            ->has('accounts')
            ->has('cycle')
            ->has('totalSpent')
            ->has('safeToSpend')
        );
});

test('budgets page shows categories with correct spent amounts for current cycle', function () {
    $user = User::factory()->create();
    Setting::factory()->create([
        'user_id' => $user->id,
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => Carbon::now()->startOfMonth()->toDateString(),
    ]);

    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 3000]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'budget_category_id' => $category->id,
        'amount' => 500,
        'transacted_at' => Carbon::now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('budgets'))
        ->assertInertia(fn ($page) => $page
            ->component('Budgets')
            ->where('totalSpent', 500)
            ->has('categories', 1, fn ($cat) => $cat
                ->where('id', $category->id)
                ->where('spent', 500)
                ->etc()
            )
        );
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

test('store income transaction increments account balance and is excluded from totalSpent', function () {
    $user = User::factory()->create();
    Setting::factory()->create([
        'user_id' => $user->id,
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => Carbon::now()->startOfMonth()->toDateString(),
    ]);
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

    $this->actingAs($user)
        ->get(route('budgets'))
        ->assertInertia(fn ($page) => $page->where('totalSpent', 0));
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

test('daily budget is zero when spending exceeds the cycle budget', function () {
    $user = User::factory()->create();
    Setting::factory()->create([
        'user_id' => $user->id,
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => Carbon::now()->startOfMonth()->toDateString(),
    ]);

    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 1000]);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'budget_category_id' => $category->id,
        'type' => 'expense',
        'amount' => 5000,
        'transacted_at' => Carbon::now()->toDateString(),
    ]);

    $this->actingAs($user)
        ->get(route('budgets'))
        ->assertInertia(fn ($page) => $page
            ->where('safeToSpend', -4000)
            ->where('dailyBudget', 0)
        );
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

test('ready to assign includes leftover from previous cycle', function () {
    $user = User::factory()->create();
    $anchor = Carbon::now()->startOfMonth()->toDateString();
    Setting::factory()->create([
        'user_id' => $user->id,
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => $anchor,
    ]);

    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 50000]);
    $category = BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 20000]);
    SinkingFund::factory()->for($user)->create(['per_month' => 2000, 'saved' => 5000]);

    // Spend ₱12k in the previous cycle (leaving ₱6k leftover: 20k − 12k − 2k)
    $prevCycleDate = Carbon::now()->startOfMonth()->subDay()->toDateString();
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'budget_category_id' => $category->id,
        'type' => 'expense',
        'amount' => 12000,
        'transacted_at' => $prevCycleDate,
    ]);

    // Current cycle: readyToAssign should include the ₱6k carryover
    // base: 50000 − 5000 − 20000 = 25000, plus 6000 leftover = 31000
    $this->actingAs($user)
        ->get(route('budgets'))
        ->assertInertia(fn ($page) => $page
            ->where('prevCycleLeftover', 6000)
            ->where('readyToAssign', 31000)
        );
});

test('ready to assign has no carryover when previous cycle had no spending', function () {
    $user = User::factory()->create();
    Setting::factory()->create([
        'user_id' => $user->id,
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => Carbon::now()->startOfMonth()->toDateString(),
    ]);

    Account::factory()->create(['user_id' => $user->id, 'balance' => 30000]);
    BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 20000]);

    $this->actingAs($user)
        ->get(route('budgets'))
        ->assertInertia(fn ($page) => $page
            ->where('prevCycleLeftover', 0)
            ->where('readyToAssign', 10000)
        );
});

test('budgets page navigates to previous cycle via query param', function () {
    $user = User::factory()->create();
    $anchor = Carbon::now()->subDays(60)->toDateString();
    Setting::factory()->create([
        'user_id' => $user->id,
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => $anchor,
    ]);

    $prevStart = Carbon::parse($anchor)->toDateString();

    $this->actingAs($user)
        ->get(route('budgets', ['cycle_start' => $prevStart]))
        ->assertInertia(fn ($page) => $page
            ->component('Budgets')
            ->where('cycle.start', $prevStart)
        );
});
