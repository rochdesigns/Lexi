<?php

use App\Models\BudgetCategory;
use App\Models\EnvelopeAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;

test('an envelope assignment resolves its polymorphic envelope via the morph map', function () {
    $user = User::factory()->create();
    $category = BudgetCategory::factory()->for($user)->create();

    $assignment = EnvelopeAssignment::factory()->create([
        'user_id' => $user->id,
        'envelope_type' => 'budget_category',
        'envelope_id' => $category->id,
        'cycle_start' => '2026-08-01',
        'assigned' => 400,
    ]);

    expect($assignment->envelope->is($category))->toBeTrue();
});

test('the unique constraint prevents two assignments for the same envelope and cycle', function () {
    $user = User::factory()->create();
    $category = BudgetCategory::factory()->for($user)->create();

    EnvelopeAssignment::factory()->create([
        'user_id' => $user->id, 'envelope_type' => 'budget_category', 'envelope_id' => $category->id,
        'cycle_start' => '2026-08-01', 'assigned' => 400,
    ]);

    expect(fn () => EnvelopeAssignment::factory()->create([
        'user_id' => $user->id, 'envelope_type' => 'budget_category', 'envelope_id' => $category->id,
        'cycle_start' => '2026-08-01', 'assigned' => 500,
    ]))->toThrow(QueryException::class);
});
