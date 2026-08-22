<?php

use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\RecurringBill;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\User;
use App\Services\EnvelopeActivityService;
use App\Support\CyclePeriod;
use Carbon\Carbon;

function cycleFor(string $start, string $end): CyclePeriod
{
    return new CyclePeriod(Carbon::parse($start), Carbon::parse($end));
}

test('debt activity sums expense transactions tagged with the debt within the cycle', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();
    Transaction::factory()->for($user)->create(['debt_id' => $debt->id, 'type' => 'expense', 'amount' => 150, 'transacted_at' => '2026-08-10']);
    Transaction::factory()->for($user)->create(['debt_id' => $debt->id, 'type' => 'expense', 'amount' => 999, 'transacted_at' => '2026-09-05']);

    $activity = (new EnvelopeActivityService)->activity($debt, cycleFor('2026-08-01', '2026-08-31'));

    expect($activity)->toBe(150.0);
});

test('sinking fund activity counts a top-up transfer but not a spend-down expense', function () {
    $user = User::factory()->create();
    $fund = SinkingFund::factory()->for($user)->create();
    Transaction::factory()->for($user)->create(['sinking_fund_id' => $fund->id, 'type' => 'transfer', 'amount' => 100, 'transacted_at' => '2026-08-10']);
    Transaction::factory()->for($user)->create(['sinking_fund_id' => $fund->id, 'type' => 'expense', 'amount' => 40, 'transacted_at' => '2026-08-12']);

    $activity = (new EnvelopeActivityService)->activity($fund, cycleFor('2026-08-01', '2026-08-31'));

    expect($activity)->toBe(100.0);
});

test('recurring bill activity sums expenses tagged with its linked category, excluding debt-tagged ones', function () {
    $user = User::factory()->create();
    $category = BudgetCategory::factory()->for($user)->create();
    $bill = RecurringBill::factory()->for($user)->create(['budget_category_id' => $category->id]);
    Transaction::factory()->for($user)->create(['budget_category_id' => $category->id, 'type' => 'expense', 'amount' => 549, 'transacted_at' => '2026-08-05']);
    $debt = Debt::factory()->for($user)->create();
    Transaction::factory()->for($user)->create(['budget_category_id' => $category->id, 'debt_id' => $debt->id, 'type' => 'expense', 'amount' => 75, 'transacted_at' => '2026-08-06']);

    $activity = (new EnvelopeActivityService)->activity($bill, cycleFor('2026-08-01', '2026-08-31'));

    expect($activity)->toBe(549.0);
});

test('recurring bill with no linked category has zero activity', function () {
    $bill = RecurringBill::factory()->for(User::factory())->create(['budget_category_id' => null]);

    $activity = (new EnvelopeActivityService)->activity($bill, cycleFor('2026-08-01', '2026-08-31'));

    expect($activity)->toBe(0.0);
});

test('budget category activity excludes debt-tagged transactions on the same category', function () {
    $user = User::factory()->create();
    $category = BudgetCategory::factory()->for($user)->create();
    Transaction::factory()->for($user)->create(['budget_category_id' => $category->id, 'type' => 'expense', 'amount' => 60, 'transacted_at' => '2026-08-05']);
    $debt = Debt::factory()->for($user)->create();
    Transaction::factory()->for($user)->create(['budget_category_id' => $category->id, 'debt_id' => $debt->id, 'type' => 'expense', 'amount' => 200, 'transacted_at' => '2026-08-06']);

    $activity = (new EnvelopeActivityService)->activity($category, cycleFor('2026-08-01', '2026-08-31'));

    expect($activity)->toBe(60.0);
});
