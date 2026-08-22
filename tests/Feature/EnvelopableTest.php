<?php

use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\RecurringBill;
use App\Models\SinkingFund;
use App\Models\User;

test('a budget category exposes its envelope fields from limit_amount', function () {
    $category = BudgetCategory::factory()->for(User::factory())->create(['name' => 'Groceries', 'limit_amount' => 450]);

    expect($category->envelopeName())->toBe('Groceries');
    expect($category->defaultAssignedAmount())->toBe(450.0);
});

test('a debt exposes its envelope fields from per_month', function () {
    $debt = Debt::factory()->for(User::factory())->create(['name' => 'Car loan', 'per_month' => 300]);

    expect($debt->envelopeName())->toBe('Car loan');
    expect($debt->defaultAssignedAmount())->toBe(300.0);
});

test('a recurring bill exposes its envelope fields from amount', function () {
    $bill = RecurringBill::factory()->for(User::factory())->create(['name' => 'Netflix', 'amount' => 549]);

    expect($bill->envelopeName())->toBe('Netflix');
    expect($bill->defaultAssignedAmount())->toBe(549.0);
});

test('a sinking fund exposes its envelope fields from per_month', function () {
    $fund = SinkingFund::factory()->for(User::factory())->create(['name' => 'Vacation', 'per_month' => 120]);

    expect($fund->envelopeName())->toBe('Vacation');
    expect($fund->defaultAssignedAmount())->toBe(120.0);
});
