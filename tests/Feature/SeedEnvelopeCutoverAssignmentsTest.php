<?php

use App\Models\BudgetCategory;
use App\Models\EnvelopeAssignment;
use App\Models\Setting;
use App\Models\User;
use App\Services\BudgetCycle;

test('seeding creates one assignment per envelope for the current cycle', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    $category = BudgetCategory::factory()->for($user)->create(['limit_amount' => 275]);

    $this->artisan('envelopes:seed-cutover')->assertSuccessful();

    $current = (new BudgetCycle($user->setting))->current();
    $row = EnvelopeAssignment::query()->whereMorphedTo('envelope', $category)
        ->whereDate('cycle_start', $current->start->toDateString())->first();

    expect($row)->not->toBeNull();
    expect((float) $row->assigned)->toBe(275.0);
});

test('seeding is idempotent and does not duplicate existing rows', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    BudgetCategory::factory()->for($user)->create(['limit_amount' => 275]);

    $this->artisan('envelopes:seed-cutover')->assertSuccessful();
    $this->artisan('envelopes:seed-cutover')->assertSuccessful();

    expect(EnvelopeAssignment::count())->toBe(1);
});
