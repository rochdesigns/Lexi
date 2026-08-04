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
