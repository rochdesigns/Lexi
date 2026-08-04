<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

test('guest is redirected from reports page', function () {
    $this->get(route('reports'))->assertRedirect(route('login'));
});

test('reports page renders with Inertia component and month period by default', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->component('Reports')
            ->where('period.type', 'month')
            ->where('period.label', 'August 2026')
            ->where('period.prevDate', '2026-07-15')
            ->where('period.isCurrent', true)
            ->where('period.nextDate', null)
        );

    Carbon::setTestNow();
});

test('reports page resolves quarter and year periods', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'quarter']))
        ->assertInertia(fn ($page) => $page
            ->where('period.type', 'quarter')
            ->where('period.label', 'Q3 2026')
        );

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'year']))
        ->assertInertia(fn ($page) => $page
            ->where('period.type', 'year')
            ->where('period.label', '2026')
        );

    Carbon::setTestNow();
});

test('reports page allows navigating to a past period and re-enables next', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month', 'date' => '2026-06-01']))
        ->assertInertia(fn ($page) => $page
            ->where('period.label', 'June 2026')
            ->where('period.isCurrent', false)
            ->where('period.nextDate', '2026-07-01')
        );

    Carbon::setTestNow();
});

test('reports overview computes income, expenses, and net for the month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 5000, 'transacted_at' => '2026-08-05']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 1200, 'transacted_at' => '2026-08-10']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 999, 'transacted_at' => '2026-07-31']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->where('overview.income', 5000)
            ->where('overview.expenses', 1200)
            ->where('overview.net', 3800)
        );

    Carbon::setTestNow();
});

test('reports overview scales budget total by months in period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 1000]);
    BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 500]);

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month']))
        ->assertInertia(fn ($page) => $page->where('overview.budgetTotal', 1500));

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'quarter']))
        ->assertInertia(fn ($page) => $page->where('overview.budgetTotal', 4500));

    Carbon::setTestNow();
});

test('reports overview projects a full-period total from spending so far', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-11'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 1100, 'transacted_at' => '2026-08-10']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page->where('overview.projectedTotal', 3100));

    Carbon::setTestNow();
});

test('reports overview omits projected total for a past period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month', 'date' => '2026-06-01']))
        ->assertInertia(fn ($page) => $page->where('overview.projectedTotal', null));

    Carbon::setTestNow();
});

test('reports overview budget used pct is zero with no budget categories', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page->where('overview.budgetUsedPct', 0));

    Carbon::setTestNow();
});

test('reports spending groups expenses by category and sorts by amount', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $groceries = BudgetCategory::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $dining = BudgetCategory::factory()->create(['user_id' => $user->id, 'name' => 'Dining out']);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => $groceries->id, 'type' => 'expense', 'amount' => 300, 'transacted_at' => '2026-08-05']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => $dining->id, 'type' => 'expense', 'amount' => 600, 'transacted_at' => '2026-08-06']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => null, 'type' => 'expense', 'amount' => 100, 'transacted_at' => '2026-08-07']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->has('spending', 3)
            ->where('spending.0.category.name', 'Dining out')
            ->where('spending.0.amount', 600)
            ->where('spending.0.pct', 0.6)
            ->where('spending.1.category.name', 'Groceries')
            ->where('spending.1.amount', 300)
            ->where('spending.2.category.name', 'Uncategorized')
            ->where('spending.2.amount', 100)
            ->where('spending.2.pct', 0.1)
        );

    Carbon::setTestNow();
});

test('reports budget vs actual scales limit by months and flags over/under', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $groceries = BudgetCategory::factory()->create(['user_id' => $user->id, 'name' => 'Groceries', 'limit_amount' => 1000]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => $groceries->id, 'type' => 'expense', 'amount' => 1200, 'transacted_at' => '2026-08-05']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->has('budgetVsActual', 1)
            ->where('budgetVsActual.0.category.name', 'Groceries')
            ->where('budgetVsActual.0.budgeted', 1000)
            ->where('budgetVsActual.0.actual', 1200)
            ->where('budgetVsActual.0.status', 'over')
        );

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'quarter']))
        ->assertInertia(fn ($page) => $page
            ->where('budgetVsActual.0.budgeted', 3000)
            ->where('budgetVsActual.0.status', 'under')
        );

    Carbon::setTestNow();
});
