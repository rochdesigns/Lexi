<?php

use App\Models\BudgetCategory;
use App\Models\RecurringBill;
use App\Models\SinkingFund;
use App\Models\User;
use App\Services\BudgetCycle;
use App\Services\UpcomingBills;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('within returns bills due in the next 7 days and excludes further-out ones', function () {
    $user = User::factory()->create();
    $category = BudgetCategory::factory()->for($user)->create();
    $cycle = (new BudgetCycle($user->setting))->current();

    RecurringBill::factory()->for($user)->create([
        'name' => 'Due soon', 'day_of_month' => Carbon::now()->addDays(3)->day, 'budget_category_id' => $category->id,
    ]);
    SinkingFund::factory()->for($user)->create([
        'name' => 'Far off fund', 'due_date' => Carbon::now()->addDays(60)->toDateString(),
    ]);

    $bills = (new UpcomingBills)->within($user, $cycle);

    expect($bills->pluck('name'))->toContain('Due soon');
    expect($bills->pluck('name'))->not->toContain('Far off fund');
});

test('within respects a custom day window', function () {
    $user = User::factory()->create();
    $cycle = (new BudgetCycle($user->setting))->current();

    SinkingFund::factory()->for($user)->create([
        'name' => 'Mid-range fund', 'due_date' => Carbon::now()->addDays(20)->toDateString(),
    ]);

    expect((new UpcomingBills)->within($user, $cycle, 7)->pluck('name'))->not->toContain('Mid-range fund');
    expect((new UpcomingBills)->within($user, $cycle, 30)->pluck('name'))->toContain('Mid-range fund');
});

test('recurring bills carry budget_category_id and category so marking paid can categorize the expense', function () {
    $user = User::factory()->create();
    $category = BudgetCategory::factory()->for($user)->create();
    $cycle = (new BudgetCycle($user->setting))->current();

    RecurringBill::factory()->for($user)->create([
        'name' => 'Due soon', 'day_of_month' => Carbon::now()->addDays(3)->day, 'budget_category_id' => $category->id,
    ]);

    $bill = (new UpcomingBills)->within($user, $cycle)->firstWhere('name', 'Due soon');

    expect($bill['budget_category_id'])->toBe($category->id);
    expect($bill['category']['id'])->toBe($category->id);
});
