<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

test('guest is redirected from reports page', function () {
    $this->get(route('reports'))->assertRedirect(route('login'));
});

test('reports page renders with Inertia component and cycle period by default', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->component('Reports')
            ->where('period.type', 'cycle')
            ->where('period.label', 'August 2026')
            ->where('period.prevDate', '2026-07-01')
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

test('reports cash flow buckets the month weekly with actuals and projections', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 3100]);

    // 750 spent by "today" (Aug 15) over 15 elapsed days -> daily trend rate of 50
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 750, 'transacted_at' => '2026-08-05']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->has('cashFlow.series', 10)
            ->where('cashFlow.series.0.label', 'Aug 1')
            ->where('cashFlow.series.0.expenses', 750)
            ->where('cashFlow.series.0.net', -750)
            ->where('cashFlow.series.1.expenses', 0)
            ->where('cashFlow.series.3.expenses', null)
            ->where('cashFlow.series.4.expenses', null)
            ->where('cashFlow.series.5.label', 'Sep 1')
            ->where('cashFlow.series.5.expenses', null)
            ->where('cashFlow.series.9.label', 'Sep 29')
            ->where('cashFlow.series.9.expenses', null)
            ->where('cashFlow.trendLine', [null, null, -1050, -1400, -1550, -1900, -2250, -2600, -2950, -3050])
            ->where('cashFlow.budgetLine', [null, null, -1350, -2050, -2350, -3050, -3750, -4450, -5150, -5350])
        );

    Carbon::setTestNow();
});

test('reports cash flow buckets a quarter into 3 monthly buckets, extended one quarter forward', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'quarter']))
        ->assertInertia(fn ($page) => $page
            ->has('cashFlow.series', 6)
            ->where('cashFlow.series.0.label', 'Jul')
            ->where('cashFlow.series.1.label', 'Aug')
            ->where('cashFlow.series.2.label', 'Sep')
            ->where('cashFlow.series.3.label', 'Oct')
            ->where('cashFlow.series.4.label', 'Nov')
            ->where('cashFlow.series.5.label', 'Dec')
        );

    Carbon::setTestNow();
});

test('reports cash flow buckets a year into 12 monthly buckets, extended one year forward', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'year']))
        ->assertInertia(fn ($page) => $page
            ->has('cashFlow.series', 24)
            ->where('cashFlow.series.0.label', 'Jan')
            ->where('cashFlow.series.11.label', 'Dec')
            ->where('cashFlow.series.12.label', 'Jan')
            ->where('cashFlow.series.23.label', 'Dec')
        );

    Carbon::setTestNow();
});

test('reports cash flow does not extend forward when viewing a past month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month', 'date' => '2026-06-01']))
        ->assertInertia(fn ($page) => $page->has('cashFlow.series', 5));

    Carbon::setTestNow();
});

test('reports cash flow projections account for income continuing, not just expenses draining', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 3100]);

    // 15 elapsed days: income 1500 (100/day) and expenses 750 (50/day) -> net daily
    // trend rate of +50/day, and a budget daily rate (3100/31=100/day) that exactly
    // matches the income rate -> budget-line net trend should be flat, not draining.
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 1500, 'transacted_at' => '2026-08-05']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 750, 'transacted_at' => '2026-08-05']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->where('cashFlow.trendLine', [null, null, 1050, 1400, 1550, 1900, 2250, 2600, 2950, 3050])
            ->where('cashFlow.budgetLine', [null, null, 750, 750, 750, 750, 750, 750, 750, 750])
        );

    Carbon::setTestNow();
});

test('reports cash flow has no projection lines for a past period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month', 'date' => '2026-06-01']))
        ->assertInertia(fn ($page) => $page
            ->where('cashFlow.trendLine', null)
            ->where('cashFlow.budgetLine', null)
        );

    Carbon::setTestNow();
});

test('reports page falls back to current period when date query param is unparseable', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['date' => 'not-a-real-date']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('period.label', 'August 2026')
            ->where('period.isCurrent', true)
            ->where('period.nextDate', null)
        );

    Carbon::setTestNow();
});

test('reports page clamps a future date query param to the current period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['date' => '2099-01-01']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('period.label', 'August 2026')
            ->where('period.isCurrent', true)
            ->where('period.nextDate', null)
        );

    Carbon::setTestNow();
});

test('reports payoff computes months and date for a debt with no interest', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    Debt::factory()->create(['user_id' => $user->id, 'name' => 'Car Loan', 'balance' => 1200, 'per_month' => 100, 'interest_rate' => null]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->where('payoff.debts.0.months', 12)
            ->where('payoff.debts.0.payoffDate', 'Aug 2027')
            ->where('payoff.debts.0.onTrack', true)
        );

    Carbon::setTestNow();
});

test('reports payoff flags a debt as not on track when payment does not cover interest', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    Debt::factory()->create([
        'user_id' => $user->id,
        'balance' => 10000,
        'per_month' => 50,
        'interest_rate' => 24,
        'interest_rate_period' => 'annual',
    ]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->where('payoff.debts.0.onTrack', false)
            ->where('payoff.debts.0.months', null)
            ->where('payoff.debts.0.payoffDate', null)
        );

    Carbon::setTestNow();
});

test('reports payoff computes months and date for a goal', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    SinkingFund::factory()->create(['user_id' => $user->id, 'saved' => 2000, 'target' => 5000, 'per_month' => 1000]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->where('payoff.goals.0.months', 3)
            ->where('payoff.goals.0.targetDate', 'Nov 2026')
        );

    Carbon::setTestNow();
});

test('reports payoff shows null months for a goal with no monthly amount', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    SinkingFund::factory()->create(['user_id' => $user->id, 'saved' => 1000, 'target' => 5000, 'per_month' => 0]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page->where('payoff.goals.0.months', null));

    Carbon::setTestNow();
});

test('reports payoff excludes paid-off debts and completed goals', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    Debt::factory()->create(['user_id' => $user->id, 'balance' => 0]);
    SinkingFund::factory()->create(['user_id' => $user->id, 'saved' => 5000, 'target' => 5000]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->has('payoff.debts', 0)
            ->has('payoff.goals', 0)
        );

    Carbon::setTestNow();
});

test('the cycle period option scopes reports to the configured budget cycle, not the calendar month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-01'));
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 31,
        'budget_cycle_anchor' => '2026-06-24',
    ]);

    $user->transactions()->create([
        'account_id' => $user->accounts()->create(['name' => 'Cash', 'type' => 'cash', 'balance' => 0])->id,
        'type' => 'expense',
        'amount' => 500,
        'transacted_at' => '2026-07-25', // inside the Jul 25 – Aug 24 cycle, but not the calendar month of Aug 1
    ]);

    $this->actingAs($user)
        ->get('/reports?period=cycle')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports')
            ->where('period.type', 'cycle')
            ->where('period.label', 'Jul 25 – Aug 24, 2026')
        );

    Carbon::setTestNow();
});

test('the cycle period is still current at any time on its last day, not just before midnight', function () {
    // Regression test: CyclePeriod::$end for fixed-days cycles is midnight on the
    // last day, so a naive now()->between($start, $end) check would wrongly flip
    // isCurrent to false any time after 00:00:00 on that final day.
    Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00'));
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 31,
        'budget_cycle_anchor' => '2026-06-24',
    ]);

    $this->actingAs($user)
        ->get('/reports?period=cycle')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('period.label', 'Jul 25 – Aug 24, 2026')
            ->where('period.isCurrent', true)
        );

    Carbon::setTestNow();
});

test('reports cash flow extends a fixed-days cycle forward to the next cycle boundary, not a calendar month', function () {
    // A 31-day fixed cycle anchored on the 24th puts "now" (Aug 15) inside the
    // Jul 25 - Aug 24 cycle. The next cycle is Aug 25 - Sep 24, so the chart's
    // forward extension must stop at Sep 24, not overshoot to Sep 30 the way
    // naive addMonthNoOverflow()->endOfMonth() month-math would.
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $user->setting()->create([
        'theme' => 'ocean',
        'budget_cycle_days' => 31,
        'budget_cycle_anchor' => '2026-06-24',
    ]);

    $this->actingAs($user)
        ->get('/reports?period=cycle')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('period.label', 'Jul 25 – Aug 24, 2026')
            ->has('cashFlow.series', 10)
            ->where('cashFlow.series.0.label', 'Jul 25')
            ->where('cashFlow.series.4.label', 'Aug 22')
            ->where('cashFlow.series.5.label', 'Aug 25')
            ->where('cashFlow.series.9.label', 'Sep 22')
        );

    Carbon::setTestNow();
});
