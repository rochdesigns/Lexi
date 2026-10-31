<?php

use App\Models\Money\CustomCategory;
use App\Models\Money\SpendingLimit;
use App\Models\Money\Transaction;
use App\Models\User;
use App\Services\Money\SpendingLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

test('setting a limit stores it from this month in centavos', function (): void {
    $this->actingAs($this->user)
        ->put('/money/spending-limits', ['category' => 'groceries', 'amount' => 8000])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $limit = $this->user->spendingLimits()->sole();

    expect($limit->category->value)->toBe('groceries')
        ->and($limit->custom_category_id)->toBeNull()
        ->and($limit->amount)->toBe(800_000)
        ->and($limit->starts_on->toDateString())->toBe('2026-10-01');
});

test('changing a limit mid-month leaves last month alone', function (): void {
    SpendingLimit::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 500_000, 'starts_on' => '2026-09-01']);

    $this->actingAs($this->user)->put('/money/spending-limits', ['category' => 'groceries', 'amount' => 7000])->assertSessionHasNoErrors();

    expect($this->user->spendingLimits()->orderBy('starts_on')->get()->map(fn (SpendingLimit $limit): array => [$limit->starts_on->toDateString(), $limit->amount])->all())
        ->toBe([['2026-09-01', 500_000], ['2026-10-01', 700_000]]);
});

test('changing it twice in a month keeps one row', function (): void {
    $this->actingAs($this->user)->put('/money/spending-limits', ['category' => 'food', 'amount' => 3000]);
    $this->actingAs($this->user)->put('/money/spending-limits', ['category' => 'food', 'amount' => 4500.50]);

    expect($this->user->spendingLimits()->sole()->amount)->toBe(450_050);
});

test('clearing a limit stores no limit from this month', function (): void {
    SpendingLimit::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 500_000, 'starts_on' => '2026-09-01']);

    $this->actingAs($this->user)->put('/money/spending-limits', ['category' => 'groceries', 'amount' => null])->assertSessionHasNoErrors();

    $row = collect((new SpendingLimits)->forMonth($this->user, Carbon::today()))->firstWhere('category', 'groceries');

    expect($row['status'])->toBe('none')
        ->and($this->user->spendingLimits()->whereDate('starts_on', '2026-10-01')->sole()->amount)->toBeNull();
});

test('a custom category limit is stored against other plus the category', function (): void {
    $coffee = CustomCategory::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->put('/money/spending-limits', ['category' => 'food', 'custom_category_id' => $coffee->id, 'amount' => 1500])
        ->assertSessionHasNoErrors();

    $limit = $this->user->spendingLimits()->sole();

    expect($limit->category->value)->toBe('other')
        ->and($limit->custom_category_id)->toBe($coffee->id);
});

test('another user\'s custom category is rejected', function (): void {
    $theirs = CustomCategory::factory()->create();

    $this->actingAs($this->user)
        ->put('/money/spending-limits', ['category' => 'other', 'custom_category_id' => $theirs->id, 'amount' => 1500])
        ->assertSessionHasErrors('custom_category_id');

    expect(SpendingLimit::count())->toBe(0);
});

test('income, transfer and adjustment are not limitable', function (string $category): void {
    $this->actingAs($this->user)
        ->put('/money/spending-limits', ['category' => $category, 'amount' => 1500])
        ->assertSessionHasErrors('category');
})->with(['income', 'transfer', 'adjustment']);

test('a zero or negative limit is rejected', function (int $amount): void {
    $this->actingAs($this->user)
        ->put('/money/spending-limits', ['category' => 'food', 'amount' => $amount])
        ->assertSessionHasErrors('amount');
})->with([0, -100]);

test('deleting a custom category removes its limit and its spending counts as other', function (): void {
    $coffee = CustomCategory::factory()->for($this->user)->create();
    SpendingLimit::factory()->custom($coffee)->create(['amount' => 200_000]);
    SpendingLimit::factory()->for($this->user)->create(['category' => 'other', 'amount' => 100_000]);
    Transaction::factory()->for($this->user)->create(['category' => 'other', 'custom_category_id' => $coffee->id, 'amount' => 120_000, 'date' => '2026-10-04']);

    $this->actingAs($this->user)->delete("/money/categories/{$coffee->id}")->assertRedirect();

    $other = collect((new SpendingLimits)->forMonth($this->user, Carbon::today()))
        ->first(fn (array $row): bool => $row['category'] === 'other' && $row['customCategoryId'] === null);

    expect(SpendingLimit::whereNotNull('custom_category_id')->count())->toBe(0)
        ->and($other)->toMatchArray(['limit' => 100_000, 'spent' => 120_000, 'status' => 'over']);
});

test('guests cannot set limits', function (): void {
    $this->put('/money/spending-limits', ['category' => 'food', 'amount' => 1500])->assertRedirect('/login');
});
