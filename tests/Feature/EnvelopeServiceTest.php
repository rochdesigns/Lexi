<?php

use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\RecurringBill;
use App\Models\SinkingFund;
use App\Models\User;
use App\Services\EnvelopeService;

test('assignable envelopes include every debt, bill, and sinking fund, plus unclaimed categories only', function () {
    $user = User::factory()->create();
    $claimed = BudgetCategory::factory()->for($user)->create(['name' => 'Claimed by a bill']);
    $unclaimed = BudgetCategory::factory()->for($user)->create(['name' => 'Groceries']);
    RecurringBill::factory()->for($user)->create(['name' => 'Netflix', 'budget_category_id' => $claimed->id]);
    $debt = Debt::factory()->for($user)->create(['name' => 'Car loan']);
    $fund = SinkingFund::factory()->for($user)->create(['name' => 'Vacation']);

    $envelopes = (new EnvelopeService)->assignableEnvelopes($user);

    expect($envelopes->pluck('name')->sort()->values()->all())
        ->toEqual(['Car loan', 'Groceries', 'Netflix', 'Vacation']);
    expect($envelopes->contains(fn ($e) => $e->is($debt)))->toBeTrue();
    expect($envelopes->contains(fn ($e) => $e->is($fund)))->toBeTrue();
});

test('envelopeType maps each model to its morph key', function () {
    $service = new EnvelopeService;
    $user = User::factory()->create();

    expect($service->envelopeType(BudgetCategory::factory()->for($user)->create()))->toBe('budget_category');
    expect($service->envelopeType(Debt::factory()->for($user)->create()))->toBe('debt');
    expect($service->envelopeType(RecurringBill::factory()->for($user)->create()))->toBe('recurring_bill');
    expect($service->envelopeType(SinkingFund::factory()->for($user)->create()))->toBe('sinking_fund');
});
