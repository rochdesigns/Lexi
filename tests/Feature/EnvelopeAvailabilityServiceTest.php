<?php

use App\Models\BudgetCategory;
use App\Models\EnvelopeAssignment;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetCycle;
use App\Services\EnvelopeActivityService;
use App\Services\EnvelopeAvailabilityService;
use App\Services\EnvelopeService;
use Carbon\Carbon;

test('a positive leftover rolls forward into the next cycle default assignment', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => '2026-01-01', 'cycle_type' => 'fixed_days']);
    $category = BudgetCategory::factory()->for($user)->create(['limit_amount' => 100]);
    $budgetCycle = new BudgetCycle($user->setting);
    $cycle1 = $budgetCycle->containing(Carbon::parse('2026-01-01'));
    $cycle2 = $budgetCycle->next($cycle1);

    EnvelopeAssignment::factory()->create([
        'user_id' => $user->id, 'envelope_type' => 'budget_category', 'envelope_id' => $category->id,
        'cycle_start' => $cycle1->start->toDateString(), 'assigned' => 100,
    ]);
    Transaction::factory()->for($user)->create([
        'budget_category_id' => $category->id, 'type' => 'expense', 'amount' => 40,
        'transacted_at' => $cycle1->start->copy()->addDays(5)->toDateString(),
    ]);

    $service = new EnvelopeAvailabilityService(new EnvelopeService, new EnvelopeActivityService);

    expect($service->available($category, $budgetCycle, $cycle1))->toBe(60.0);
    // cycle2 has no explicit row, so it defaults to limit_amount (100), no activity yet:
    expect($service->available($category, $budgetCycle, $cycle2))->toBe(160.0);
});

test('an overspend carries forward as a negative balance rather than resetting to zero', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => '2026-01-01', 'cycle_type' => 'fixed_days']);
    $category = BudgetCategory::factory()->for($user)->create(['limit_amount' => 50]);
    $budgetCycle = new BudgetCycle($user->setting);
    $cycle1 = $budgetCycle->containing(Carbon::parse('2026-01-01'));
    $cycle2 = $budgetCycle->next($cycle1);

    EnvelopeAssignment::factory()->create([
        'user_id' => $user->id, 'envelope_type' => 'budget_category', 'envelope_id' => $category->id,
        'cycle_start' => $cycle1->start->toDateString(), 'assigned' => 50,
    ]);
    Transaction::factory()->for($user)->create([
        'budget_category_id' => $category->id, 'type' => 'expense', 'amount' => 80,
        'transacted_at' => $cycle1->start->copy()->addDays(5)->toDateString(),
    ]);

    $service = new EnvelopeAvailabilityService(new EnvelopeService, new EnvelopeActivityService);

    expect($service->available($category, $budgetCycle, $cycle1))->toBe(-30.0);
    // cycle2 defaults to 50 assigned, no activity: -30 + 50 - 0 = 20
    expect($service->available($category, $budgetCycle, $cycle2))->toBe(20.0);
});

test('a freshly created envelope with no assignment rows starts its rollover at its own creation cycle', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => '2026-01-01', 'cycle_type' => 'fixed_days']);
    $budgetCycle = new BudgetCycle($user->setting);
    $current = $budgetCycle->current();
    $category = BudgetCategory::factory()->for($user)->create(['limit_amount' => 200]);

    $service = new EnvelopeAvailabilityService(new EnvelopeService, new EnvelopeActivityService);
    // created_at is a cast attribute (CarbonImmutable, per this app's Date::use() config) — containing()
    // requires a real Carbon\Carbon, same normalization the service itself does internally.
    $creationCycle = $budgetCycle->containing(Carbon::instance($category->created_at));

    expect($service->available($category, $budgetCycle, $creationCycle))->toBe(200.0);
});
