<?php

use App\Models\BudgetCategory;
use App\Models\RecurringBill;
use App\Models\User;
use App\Support\CyclePeriod;
use Carbon\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can create a recurring bill', function () {
    $this->post('/recurring-bills', [
        'name' => 'Netflix',
        'icon' => 'play_circle',
        'color' => '#c0512c',
        'bg' => '#fbe2d6',
        'amount' => 15.49,
        'day_of_month' => 5,
        'budget_category_id' => null,
    ])->assertRedirect();

    expect(RecurringBill::where('user_id', $this->user->id)->where('name', 'Netflix')->exists())->toBeTrue();
});

test('can create a recurring bill with a category', function () {
    $category = BudgetCategory::factory()->create(['user_id' => $this->user->id]);

    $this->post('/recurring-bills', [
        'name' => 'Gym',
        'icon' => 'fitness_center',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'amount' => 29.00,
        'day_of_month' => 15,
        'budget_category_id' => $category->id,
    ])->assertRedirect();

    expect(RecurringBill::where('user_id', $this->user->id)->where('name', 'Gym')->value('budget_category_id'))->toBe($category->id);
});

test('creating a bill with a category adds amount to category limit', function () {
    $category = BudgetCategory::factory()->create(['user_id' => $this->user->id, 'limit_amount' => 100.00]);

    $this->post('/recurring-bills', [
        'name' => 'Gym',
        'icon' => 'fitness_center',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'amount' => 50.00,
        'day_of_month' => 15,
        'budget_category_id' => $category->id,
    ])->assertRedirect();

    expect((float) $category->fresh()->limit_amount)->toBe(150.00);
});

test('updating a bill amount adjusts the category limit', function () {
    $category = BudgetCategory::factory()->create(['user_id' => $this->user->id, 'limit_amount' => 50.00]);
    $bill = RecurringBill::factory()->create(['user_id' => $this->user->id, 'amount' => 50.00, 'budget_category_id' => $category->id]);

    $this->patch("/recurring-bills/{$bill->id}", [
        'name' => $bill->name,
        'icon' => $bill->icon,
        'color' => $bill->color,
        'bg' => $bill->bg,
        'amount' => 80.00,
        'day_of_month' => $bill->day_of_month,
        'budget_category_id' => $category->id,
    ])->assertRedirect();

    expect((float) $category->fresh()->limit_amount)->toBe(80.00);
});

test('updating a bill category moves amount between categories', function () {
    $catA = BudgetCategory::factory()->create(['user_id' => $this->user->id, 'limit_amount' => 100.00]);
    $catB = BudgetCategory::factory()->create(['user_id' => $this->user->id, 'limit_amount' => 0.00]);
    $bill = RecurringBill::factory()->create(['user_id' => $this->user->id, 'amount' => 30.00, 'budget_category_id' => $catA->id]);

    $this->patch("/recurring-bills/{$bill->id}", [
        'name' => $bill->name,
        'icon' => $bill->icon,
        'color' => $bill->color,
        'bg' => $bill->bg,
        'amount' => 30.00,
        'day_of_month' => $bill->day_of_month,
        'budget_category_id' => $catB->id,
    ])->assertRedirect();

    expect((float) $catA->fresh()->limit_amount)->toBe(70.00);
    expect((float) $catB->fresh()->limit_amount)->toBe(30.00);
});

test('deleting a bill subtracts its amount from the category limit', function () {
    $category = BudgetCategory::factory()->create(['user_id' => $this->user->id, 'limit_amount' => 200.00]);
    $bill = RecurringBill::factory()->create(['user_id' => $this->user->id, 'amount' => 50.00, 'budget_category_id' => $category->id]);

    $this->delete("/recurring-bills/{$bill->id}")->assertRedirect();

    expect((float) $category->fresh()->limit_amount)->toBe(150.00);
});

test('can update a recurring bill', function () {
    $bill = RecurringBill::factory()->create(['user_id' => $this->user->id]);

    $this->patch("/recurring-bills/{$bill->id}", [
        'name' => 'Updated',
        'icon' => 'receipt',
        'color' => '#2f7d5b',
        'bg' => '#dff3e6',
        'amount' => 99.00,
        'day_of_month' => 20,
        'budget_category_id' => null,
    ])->assertRedirect();

    expect($bill->fresh()->name)->toBe('Updated');
});

test('cannot update another user recurring bill', function () {
    $other = User::factory()->create();
    $bill = RecurringBill::factory()->create(['user_id' => $other->id]);

    $this->patch("/recurring-bills/{$bill->id}", [
        'name' => 'Hacked',
        'icon' => 'receipt',
        'color' => '#2f7d5b',
        'bg' => '#dff3e6',
        'amount' => 1,
        'day_of_month' => 1,
        'budget_category_id' => null,
    ])->assertForbidden();
});

test('can delete a recurring bill', function () {
    $bill = RecurringBill::factory()->create(['user_id' => $this->user->id]);

    $this->delete("/recurring-bills/{$bill->id}")->assertRedirect();

    expect(RecurringBill::find($bill->id))->toBeNull();
});

test('forecastAmount returns the flat amount regardless of period', function () {
    $bill = RecurringBill::factory()->create(['user_id' => $this->user->id, 'amount' => 15.49]);
    $period = new CyclePeriod(Carbon::parse('2026-08-01'), Carbon::parse('2026-08-30'));

    expect($bill->forecastAmount($period, 0))->toBe(15.49);
    expect($bill->forecastAmount($period, 11))->toBe(15.49);
});
