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

test('assignedThroughCycle returns the accumulated assigned total distinct from available', function () {
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

    // cycle1: only the explicit assignment row (100), no rollover yet.
    expect($service->assignedThroughCycle($category, $budgetCycle, $cycle1))->toBe(100.0);
    // cycle2: explicit 100 (cycle1) + default 100 (cycle2, limit_amount, no row) = 200,
    // distinct from available() which also subtracts activity (40) to get 160.
    expect($service->assignedThroughCycle($category, $budgetCycle, $cycle2))->toBe(200.0);
    expect($service->available($category, $budgetCycle, $cycle2))->toBe(160.0);
});

test('a 3-cycle walk accumulates an intermediate cycle default assignment and activity', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => '2026-01-01', 'cycle_type' => 'fixed_days']);
    $category = BudgetCategory::factory()->for($user)->create(['limit_amount' => 100]);
    $budgetCycle = new BudgetCycle($user->setting);
    $cycle1 = $budgetCycle->containing(Carbon::parse('2026-01-01'));
    $cycle2 = $budgetCycle->next($cycle1);
    $cycle3 = $budgetCycle->next($cycle2);

    // Explicit assignment only at the epoch (cycle1). Cycle2 has no row at all, so it
    // must fall back to defaultAssignedAmount() (limit_amount) mid-walk, not just at the target.
    EnvelopeAssignment::factory()->create([
        'user_id' => $user->id, 'envelope_type' => 'budget_category', 'envelope_id' => $category->id,
        'cycle_start' => $cycle1->start->toDateString(), 'assigned' => 100,
    ]);
    Transaction::factory()->for($user)->create([
        'budget_category_id' => $category->id, 'type' => 'expense', 'amount' => 20,
        'transacted_at' => $cycle1->start->copy()->addDays(5)->toDateString(),
    ]);
    Transaction::factory()->for($user)->create([
        'budget_category_id' => $category->id, 'type' => 'expense', 'amount' => 30,
        'transacted_at' => $cycle2->start->copy()->addDays(5)->toDateString(),
    ]);

    $service = new EnvelopeAvailabilityService(new EnvelopeService, new EnvelopeActivityService);

    // assigned: 100 (cycle1 explicit) + 100 (cycle2 default) + 100 (cycle3 default) = 300
    // activity: 20 (cycle1) + 30 (cycle2) + 0 (cycle3) = 50
    // available: 300 - 50 = 250
    expect($service->assignedThroughCycle($category, $budgetCycle, $cycle3))->toBe(300.0);
    expect($service->available($category, $budgetCycle, $cycle3))->toBe(250.0);
});

test('walk guards against a target cycle before the epoch instead of looping forever', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id, 'budget_cycle_days' => 30, 'budget_cycle_anchor' => '2026-01-01', 'cycle_type' => 'fixed_days']);
    $category = BudgetCategory::factory()->for($user)->create(['limit_amount' => 100]);
    $budgetCycle = new BudgetCycle($user->setting);
    $epochCycle = $budgetCycle->containing(Carbon::parse('2026-03-01'));

    EnvelopeAssignment::factory()->create([
        'user_id' => $user->id, 'envelope_type' => 'budget_category', 'envelope_id' => $category->id,
        'cycle_start' => $epochCycle->start->toDateString(), 'assigned' => 100,
    ]);

    $pastCycle = $budgetCycle->previous($epochCycle);

    $service = new EnvelopeAvailabilityService(new EnvelopeService, new EnvelopeActivityService);

    expect($service->available($category, $budgetCycle, $pastCycle))->toBe(0.0);
    expect($service->assignedThroughCycle($category, $budgetCycle, $pastCycle))->toBe(0.0);
});
