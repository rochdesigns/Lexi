<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Models\RecurringBill;
use App\Models\Setting;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

test('guests are redirected to login', function () {
    $this->get('/money')->assertRedirect('/login');
});

test('the five headline numbers reconcile: income minus everyday expenses minus set-asides minus debt paid', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => Carbon::now()->startOfMonth()->toDateString()]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    $today = Carbon::now()->startOfMonth()->addDays(2)->toDateString();

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 50000, 'transacted_at' => $today]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 8000, 'transacted_at' => $today]);
    SinkingFund::factory()->for($user)->create(['per_month' => 2000]);

    $debt = Debt::factory()->for($user)->create();
    DebtPayment::factory()->create(['user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $account->id, 'amount' => 3000, 'paid_at' => $today]);
    // The debt payment is ALSO recorded as its own expense transaction, exactly like DebtController::makePayment does.
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'debt_id' => $debt->id, 'type' => 'expense', 'amount' => 3000, 'transacted_at' => $today]);

    $this->actingAs($user)
        ->get('/money')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Money')
            // Whole-peso amounts are asserted as plain ints, matching this codebase's existing
            // convention (see BudgetTest): PHP's json_encode() drops the fractional part of a
            // whole-number float (json_encode(50000.0) === "50000"), and AssertableInertia
            // round-trips props through json_decode(json_encode(...)) before comparing, so a
            // float literal like 50000.0 here would fail assertSame()'s strict type check even
            // though the controller correctly computed and cast a float.
            ->where('incomeReceived', 50000)
            // 8000 everyday expense; the 3000 debt-linked expense transaction is excluded here
            // so it isn't subtracted twice (it's counted once, below, as debtPaid).
            ->where('everydayExpenses', 8000)
            ->where('setAsidesHeld', 2000)
            ->where('debtPaid', 3000)
            ->where('leftForDailyExpenses', 50000 - 8000 - 2000 - 3000)
        );
});

test('bills due within 7 days appear, further-out bills do not', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    $category = BudgetCategory::factory()->for($user)->create();

    RecurringBill::factory()->for($user)->create([
        'name' => 'Due soon', 'day_of_month' => Carbon::now()->addDays(3)->day, 'budget_category_id' => $category->id,
    ]);
    RecurringBill::factory()->for($user)->create([
        'name' => 'Due later', 'day_of_month' => Carbon::now()->addDays(20)->day, 'budget_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)->get('/money')->assertOk();
    $bills = $response->viewData('page')['props']['upcomingBills'];
    $names = collect($bills)->pluck('name');

    expect($names)->toContain('Due soon');
    // "Due later" might land in this cycle or the next depending on today's date relative to
    // day-of-month math — the assertion that matters is it's never within the 7-day window.
    expect(collect($bills)->firstWhere('name', 'Due later'))->toBeNull();
});

test('upcoming recurring bills carry their budget_category_id so marking paid categorizes the expense', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    $category = BudgetCategory::factory()->for($user)->create();

    RecurringBill::factory()->for($user)->create([
        'name' => 'Due soon', 'day_of_month' => Carbon::now()->addDays(3)->day, 'budget_category_id' => $category->id,
    ]);

    $response = $this->actingAs($user)->get('/money')->assertOk();
    $bill = collect($response->viewData('page')['props']['upcomingBills'])->firstWhere('name', 'Due soon');

    expect($bill['budget_category_id'])->toBe($category->id);
    expect($bill['category']['id'])->toBe($category->id);
});

test('only categories that are actually over their limit are returned', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => Carbon::now()->startOfMonth()->toDateString()]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    $today = Carbon::now()->startOfMonth()->addDay()->toDateString();

    $over = BudgetCategory::factory()->for($user)->create(['name' => 'Over', 'limit_amount' => 1000]);
    $under = BudgetCategory::factory()->for($user)->create(['name' => 'Under', 'limit_amount' => 1000]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => $over->id, 'type' => 'expense', 'amount' => 1500, 'transacted_at' => $today]);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => $under->id, 'type' => 'expense', 'amount' => 500, 'transacted_at' => $today]);

    $response = $this->actingAs($user)->get('/money')->assertOk();
    $categories = collect($response->viewData('page')['props']['categoriesOverBudget'])->pluck('name');

    expect($categories)->toContain('Over');
    expect($categories)->not->toContain('Under');
});

test('categories with no limit set (limit_amount = 0) are never flagged as over budget', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => Carbon::now()->startOfMonth()->toDateString()]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    $today = Carbon::now()->startOfMonth()->addDay()->toDateString();

    // Never given a budget — spending anything against it trivially satisfies spent > 0,
    // but it shouldn't clutter a list that means "overspending against what you planned."
    $unbudgeted = BudgetCategory::factory()->for($user)->create(['name' => 'Unbudgeted', 'limit_amount' => 0]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => $unbudgeted->id, 'type' => 'expense', 'amount' => 250, 'transacted_at' => $today]);

    $response = $this->actingAs($user)->get('/money')->assertOk();
    $categories = collect($response->viewData('page')['props']['categoriesOverBudget'])->pluck('name');

    expect($categories)->not->toContain('Unbudgeted');
});

test('debt payoff projection is present when there is outstanding debt', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    Debt::factory()->for($user)->create(['balance' => 1200, 'per_month' => 100, 'interest_rate' => null]);

    $this->actingAs($user)
        ->get('/money')
        ->assertInertia(fn ($page) => $page
            ->where('debtFreeMonths', 12)
            ->where('debtOnTrack', true)
        );
});
