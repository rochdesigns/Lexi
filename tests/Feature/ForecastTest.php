<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\RecurringBill;
use App\Models\Setting;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

test('guests are redirected to login', function () {
    $this->get('/forecast')->assertRedirect('/login');
});

test('returns 12 periods, the first marked as current', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $periods = $response->viewData('page')['props']['periods'];

    expect($periods)->toHaveCount(12);
    expect($periods[0]['isCurrent'])->toBeTrue();
    expect(collect($periods)->slice(1)->pluck('isCurrent')->unique()->all())->toBe([false]);
});

test('a recurring bill contributes its flat amount to every period', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    RecurringBill::factory()->for($user)->create(['name' => 'Netflix', 'amount' => 1500]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $groups = $response->viewData('page')['props']['expenseGroups'];
    $billsGroup = collect($groups)->firstWhere('key', 'bills');
    $item = collect($billsGroup['subgroups'])->flatMap(fn ($g) => $g['items'])->firstWhere('name', 'Netflix');

    expect($item['amounts'])->toHaveCount(12);
    expect(collect($item['amounts'])->unique()->all())->toEqual([1500]);
});

test('a debt drops to zero starting the period it is projected to pay off', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    // 300 balance / 100 per month, no interest -> pays off in exactly 3 periods.
    Debt::factory()->for($user)->create(['name' => 'Small loan', 'balance' => 300, 'per_month' => 100, 'interest_rate' => null]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $groups = $response->viewData('page')['props']['expenseGroups'];
    $debtGroup = collect($groups)->firstWhere('key', 'debt');
    $item = collect($debtGroup['items'])->firstWhere('name', 'Small loan');

    expect($item['amounts'])->toEqual([100, 100, 100, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
});

test('debt payoff amounts stay correctly matched to their own debt when multiple debts have different payoff horizons', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    // Slow payoff: 5000 balance / 1000 per month, no interest -> pays off in exactly 5 periods.
    Debt::factory()->for($user)->create(['name' => 'Slow payoff', 'position' => 1, 'balance' => 5000, 'per_month' => 1000, 'interest_rate' => null]);
    // Fast payoff: 300 balance / 100 per month, no interest -> pays off in exactly 3 periods.
    Debt::factory()->for($user)->create(['name' => 'Fast payoff', 'position' => 2, 'balance' => 300, 'per_month' => 100, 'interest_rate' => null]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $groups = $response->viewData('page')['props']['expenseGroups'];
    $debtGroup = collect($groups)->firstWhere('key', 'debt');

    $slow = collect($debtGroup['items'])->firstWhere('name', 'Slow payoff');
    $fast = collect($debtGroup['items'])->firstWhere('name', 'Fast payoff');

    expect($slow['amounts'])->toEqual([1000, 1000, 1000, 1000, 1000, 0, 0, 0, 0, 0, 0, 0]);
    expect($fast['amounts'])->toEqual([100, 100, 100, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
});

test('a set-aside with a due date drops to an annual amortized amount as soon as its due date falls within a period', function () {
    $user = User::factory()->create();
    $anchor = Carbon::now()->startOfMonth();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => $anchor->toDateString()]);
    // Due date lands inside period 1 (Anchor+30..Anchor+59) -> period 0 (which ends before the
    // due date) keeps the pre-due catch-up rate; period 1 onward already show target/12, since
    // the due date falls within period 1 itself, not only in periods fully after it.
    SinkingFund::factory()->for($user)->create([
        'name' => 'Renewing fund', 'per_month' => 1000, 'target' => 6000, 'due_date' => $anchor->copy()->addDays(45),
    ]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $groups = $response->viewData('page')['props']['expenseGroups'];
    $setAsideGroup = collect($groups)->firstWhere('key', 'set_asides');
    $item = collect($setAsideGroup['items'])->firstWhere('name', 'Renewing fund');

    expect($item['amounts'])->toEqual([1000, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500, 500]);
});

test('a set-aside with no due date keeps its flat per_month indefinitely', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    SinkingFund::factory()->for($user)->create(['name' => 'Open-ended fund', 'per_month' => 300, 'due_date' => null]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $groups = $response->viewData('page')['props']['expenseGroups'];
    $setAsideGroup = collect($groups)->firstWhere('key', 'set_asides');
    $item = collect($setAsideGroup['items'])->firstWhere('name', 'Open-ended fund');

    expect(collect($item['amounts'])->unique()->all())->toEqual([300]);
});

test('projected income and everyday expenses average the last 3 completed cycles', function () {
    $user = User::factory()->create();
    $anchor = Carbon::now()->startOfMonth();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => $anchor->toDateString()]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    // Cycle -1, -2, -3 each get one income transaction: 1000, 2000, 3000 -> average 2000.
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 1000, 'transacted_at' => $anchor->copy()->subDays(15)]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 2000, 'transacted_at' => $anchor->copy()->subDays(45)]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 3000, 'transacted_at' => $anchor->copy()->subDays(75)]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $incomeRow = $response->viewData('page')['props']['incomeRow'];

    // Period 0 is the current (in-progress) cycle and uses live actuals, not the average.
    expect($incomeRow['amounts'][1])->toEqual(2000);
});

test('projected income falls back to a default when there is no prior transaction history', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $incomeRow = $response->viewData('page')['props']['incomeRow'];

    // A brand-new user has no completed cycles to average, so the 3-cycle average is 0 —
    // which would otherwise make every future period look like ₱0 income. Falls back to a
    // default until real history accumulates and the average naturally takes over.
    expect($incomeRow['amounts'][1])->toEqual(225000);
});

test('projected everyday expenses for future periods sum category limits, excluding categories already linked to a bill or a debt', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);

    // Not linked to anything -- counts as everyday spending.
    $groceries = BudgetCategory::factory()->for($user)->create(['name' => 'Groceries', 'group' => 'Food', 'limit_amount' => 5000]);
    $misc = BudgetCategory::factory()->for($user)->create(['name' => 'Misc', 'group' => null, 'limit_amount' => 100]);

    // Linked to a recurring bill -- already counted in the Bills row, must be excluded here
    // even though its group name ("Household") isn't "Debt" or "Subscriptions".
    $rent = BudgetCategory::factory()->for($user)->create(['name' => 'Rent', 'group' => 'Household', 'limit_amount' => 3000]);
    RecurringBill::factory()->for($user)->create(['budget_category_id' => $rent->id, 'amount' => 3000]);

    // Linked to a debt -- already counted in the Debt row.
    $creditCard = BudgetCategory::factory()->for($user)->create(['name' => 'Credit card', 'group' => 'Debt', 'limit_amount' => 2000]);
    Debt::factory()->for($user)->create(['budget_category_id' => $creditCard->id, 'balance' => 2000, 'per_month' => 200, 'interest_rate' => null]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $everydayRow = $response->viewData('page')['props']['everydayExpensesRow'];

    expect($everydayRow['amounts'][1])->toEqual(5000 + 100);
});

test('the current period shows live actual income and everyday expenses, not the projected average', function () {
    $user = User::factory()->create();
    $anchor = Carbon::now()->startOfMonth();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => $anchor->toDateString()]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    // Prior cycle would average to 5000 if used, but the current cycle's own actual is 777.
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 5000, 'transacted_at' => $anchor->copy()->subDays(15)]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 777, 'transacted_at' => $anchor->copy()->addDay()]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $incomeRow = $response->viewData('page')['props']['incomeRow'];

    expect($incomeRow['amounts'][0])->toEqual(777);
});

test('net leftover reconciles income minus everyday expenses minus every expense group subtotal', function () {
    $user = User::factory()->create();
    $anchor = Carbon::now()->startOfMonth();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => $anchor->toDateString()]);
    $account = Account::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 2000, 'transacted_at' => $anchor->copy()->subDays(15)]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 2000, 'transacted_at' => $anchor->copy()->subDays(45)]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 2000, 'transacted_at' => $anchor->copy()->subDays(75)]);
    RecurringBill::factory()->for($user)->create(['amount' => 300]);
    SinkingFund::factory()->for($user)->create(['per_month' => 200]);
    Debt::factory()->for($user)->create(['balance' => 5000, 'per_month' => 100, 'interest_rate' => null]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $props = $response->viewData('page')['props'];

    // Period 1 (first future period): 2000 income, 0 everyday expenses, 100 debt + 200 set-aside + 300 bill.
    expect($props['netLeftover'][1])->toEqual(2000 - 0 - 100 - 200 - 300);
});

test('running balance starts from account balances net of already-saved set-aside money, then accumulates net leftover', function () {
    $user = User::factory()->create();
    $anchor = Carbon::now()->startOfMonth();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => $anchor->toDateString()]);
    Account::factory()->create(['user_id' => $user->id, 'balance' => 1000]);
    Account::factory()->create(['user_id' => $user->id, 'balance' => 500]);
    // Sinking funds have no account of their own -- this "saved" money already sits inside
    // the account balances above, earmarked for a goal. It must be excluded from the starting
    // balance so running balance reflects truly uncommitted cash, not total cash on hand.
    SinkingFund::factory()->for($user)->create(['saved' => 300]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => Account::first()->id, 'type' => 'income', 'amount' => 2000, 'transacted_at' => $anchor->copy()->subDays(15)]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => Account::first()->id, 'type' => 'income', 'amount' => 2000, 'transacted_at' => $anchor->copy()->subDays(45)]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => Account::first()->id, 'type' => 'income', 'amount' => 2000, 'transacted_at' => $anchor->copy()->subDays(75)]);

    $response = $this->actingAs($user)->get('/forecast')->assertOk();
    $props = $response->viewData('page')['props'];

    // (1000 + 500) accounts - 300 already-saved set-aside money = 1200.
    expect($props['startingBalance'])->toEqual(1200);
    // Period 0 (current) has no income this cycle -> netLeftover 0 -> running balance unchanged.
    expect($props['runningBalance'][0])->toEqual(1200 + $props['netLeftover'][0]);
    // Period 1 accumulates on top of period 0's running balance.
    expect($props['runningBalance'][1])->toEqual($props['runningBalance'][0] + $props['netLeftover'][1]);
});
