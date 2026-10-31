<?php

use App\Models\Money\Bill;
use App\Models\Money\CustomCategory;
use App\Models\Money\SpendingLimit;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionCategory;
use App\Models\Money\TransactionSource;
use App\Models\User;
use App\Services\Money\Projection;
use App\Services\Money\SpendingLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

/**
 * The month's row for one category: a built-in value, or a custom category.
 *
 * @return array<string, mixed>
 */
function rowFor(User $user, string|CustomCategory $category, string $today = '2026-10-15'): array
{
    $rows = collect((new SpendingLimits)->forMonth($user, Carbon::parse($today)));

    return $category instanceof CustomCategory
        ? $rows->firstWhere('customCategoryId', $category->id)
        : $rows->first(fn (array $row): bool => $row['category'] === $category && $row['customCategoryId'] === null);
}

test('a category with no limit still shows what was spent', function (): void {
    Transaction::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 120_000, 'date' => '2026-10-03']);

    expect(rowFor($this->user, 'groceries'))->toMatchArray([
        'limit' => null, 'spent' => 120_000, 'left' => null, 'pace' => null, 'status' => 'none', 'aheadOfPace' => false,
    ]);
});

test('status is under, close at 80%, and over past the limit', function (int $spent, string $status): void {
    SpendingLimit::factory()->for($this->user)->create(['category' => 'food', 'amount' => 1_000_000]);
    Transaction::factory()->for($this->user)->create(['category' => 'food', 'amount' => $spent, 'date' => '2026-10-02']);

    expect(rowFor($this->user, 'food')['status'])->toBe($status);
})->with([[799_999, 'under'], [800_000, 'close'], [1_000_000, 'close'], [1_000_001, 'over']]);

test('pace is the limit spread evenly to today', function (): void {
    SpendingLimit::factory()->for($this->user)->create(['category' => 'transport', 'amount' => 310_000]);
    Transaction::factory()->for($this->user)->create(['category' => 'transport', 'amount' => 160_000, 'date' => '2026-10-01']);

    expect(rowFor($this->user, 'transport'))->toMatchArray(['pace' => 150_000, 'aheadOfPace' => true, 'left' => 150_000]);
});

test('an over category is not also ahead of pace', function (): void {
    SpendingLimit::factory()->for($this->user)->create(['category' => 'transport', 'amount' => 100_000]);
    Transaction::factory()->for($this->user)->create(['category' => 'transport', 'amount' => 150_000, 'date' => '2026-10-01']);

    expect(rowFor($this->user, 'transport'))->toMatchArray(['status' => 'over', 'aheadOfPace' => false, 'left' => -50_000]);
});

test('bill payments, transfers and matched statement rows are not living expenses', function (): void {
    $bill = Bill::factory()->for($this->user)->create();
    Transaction::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 900_000, 'bill_id' => $bill->id, 'date' => '2026-10-05']);
    Transaction::factory()->for($this->user)->onCard()->create(['category' => 'groceries', 'amount' => 800_000, 'bill_id' => $bill->id, 'date' => '2026-10-05']);
    Transaction::factory()->for($this->user)->transfer()->create(['amount' => 700_000, 'date' => '2026-10-05']);
    $manual = Transaction::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 50_000, 'date' => '2026-10-06']);
    Transaction::factory()->for($this->user)->create([
        'category' => 'groceries', 'amount' => 50_000, 'date' => '2026-10-06',
        'source' => TransactionSource::StatementImport, 'matched_transaction_id' => $manual->id,
    ]);

    expect(rowFor($this->user, 'groceries')['spent'])->toBe(50_000);
});

test('a card purchase counts in the month it was made', function (): void {
    Transaction::factory()->for($this->user)->onCard()->create(['category' => 'shopping', 'amount' => 230_000, 'date' => '2026-10-09']);

    expect(rowFor($this->user, 'shopping')['spent'])->toBe(230_000);
});

test('an expense dated later this month does not count yet', function (): void {
    Transaction::factory()->for($this->user)->create(['category' => 'fun', 'amount' => 40_000, 'date' => '2026-10-20']);
    Transaction::factory()->for($this->user)->create(['category' => 'fun', 'amount' => 30_000, 'date' => '2026-09-30']);

    expect(rowFor($this->user, 'fun')['spent'])->toBe(0);
});

test('custom categories are their own rows and other means plain other', function (): void {
    $coffee = CustomCategory::factory()->for($this->user)->create(['name' => 'Coffee']);
    $books = CustomCategory::factory()->for($this->user)->create(['name' => 'Books']);
    Transaction::factory()->for($this->user)->create(['category' => 'other', 'custom_category_id' => $coffee->id, 'amount' => 15_000, 'date' => '2026-10-04']);
    Transaction::factory()->for($this->user)->create(['category' => 'other', 'amount' => 9_000, 'date' => '2026-10-04']);

    $rows = (new SpendingLimits)->forMonth($this->user, Carbon::today());

    expect(array_map(fn (array $row): string => $row['categoryName'] ?? $row['category'], $rows))
        ->toBe([...TransactionCategory::expenseValues(), 'Books', 'Coffee'])
        ->and(rowFor($this->user, $coffee))->toMatchArray(['category' => 'other', 'categoryName' => 'Coffee', 'spent' => 15_000])
        ->and(rowFor($this->user, 'other'))->toMatchArray(['categoryName' => null, 'spent' => 9_000]);
});

test('another user\'s limits and spending are left out', function (): void {
    $other = User::factory()->create();
    SpendingLimit::factory()->for($other)->create(['category' => 'groceries', 'amount' => 500_000]);
    Transaction::factory()->for($other)->create(['category' => 'groceries', 'amount' => 70_000, 'date' => '2026-10-03']);

    expect(rowFor($this->user, 'groceries'))->toMatchArray(['limit' => null, 'spent' => 0]);
});

test('a limit set in september carries into october until changed', function (): void {
    SpendingLimit::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 500_000, 'starts_on' => '2026-09-01']);

    expect(rowFor($this->user, 'groceries')['limit'])->toBe(500_000);

    $october = SpendingLimit::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 700_000, 'starts_on' => '2026-10-01']);

    expect(rowFor($this->user, 'groceries')['limit'])->toBe(700_000)
        ->and(rowFor($this->user, 'groceries', '2026-09-10')['limit'])->toBe(500_000);

    $october->update(['amount' => null]);

    expect(rowFor($this->user, 'groceries'))->toMatchArray(['limit' => null, 'status' => 'none'])
        ->and(rowFor($this->user, 'groceries', '2026-11-03')['limit'])->toBeNull();
});

test('the limits spending adds up to spent this month', function (): void {
    $coffee = CustomCategory::factory()->for($this->user)->create();
    Transaction::factory()->for($this->user)->create(['category' => 'food', 'amount' => 11_000, 'date' => '2026-10-01']);
    Transaction::factory()->for($this->user)->create(['category' => 'home', 'amount' => 22_000, 'date' => '2026-10-10']);
    Transaction::factory()->for($this->user)->create(['category' => 'other', 'custom_category_id' => $coffee->id, 'amount' => 33_000, 'date' => '2026-10-15']);

    $rows = (new SpendingLimits)->forMonth($this->user, Carbon::today());

    expect(array_sum(array_column($rows, 'spent')))->toBe((new Projection)->spentThisMonth($this->user, Carbon::today()))
        ->and(array_sum(array_column($rows, 'spent')))->toBe(66_000);
});

test('total limits adds only the categories that have one', function (): void {
    SpendingLimit::factory()->for($this->user)->create(['category' => 'food', 'amount' => 300_000]);
    SpendingLimit::factory()->for($this->user)->create(['category' => 'home', 'amount' => 200_000]);

    $service = new SpendingLimits;

    expect($service->totalLimits($service->forMonth($this->user, Carbon::today())))->toBe(500_000);
});
