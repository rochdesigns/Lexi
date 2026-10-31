<?php

namespace App\Services\Money;

use App\Models\Money\CustomCategory;
use App\Models\Money\SpendingLimit;
use App\Models\Money\TransactionCategory;
use App\Models\Money\TransactionKind;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Living expenses in each category for the month, against the limit in force.
 * Limits are watched, never taken off safe to spend.
 *
 * @phpstan-type LimitRow array{category: string, customCategoryId: int|null, categoryName: string|null, limit: int|null, spent: int, left: int|null, pace: int|null, status: 'none'|'under'|'close'|'over', aheadOfPace: bool}
 */
class SpendingLimits
{
    /** A limit is close once this share of it is used. */
    public const CLOSE_PERCENT = 80;

    /**
     * One row per expense category: the built-in ones, then the user's own by
     * name. Spent counts the same entries as spent this month: counted,
     * non-bill expenses from the first of the month through today.
     *
     * @return list<LimitRow>
     */
    public function forMonth(User $user, CarbonInterface $today): array
    {
        $today = CarbonImmutable::instance($today)->startOfDay();
        $monthStart = $today->startOfMonth();

        $limits = $user->spendingLimits()
            ->whereDate('starts_on', '<=', $monthStart->toDateString())
            ->orderBy('starts_on')
            ->get()
            ->keyBy(fn (SpendingLimit $limit): string => $this->key($limit->category->value, $limit->custom_category_id))
            ->map(fn (SpendingLimit $limit): ?int => $limit->amount);

        $spent = $user->transactions()
            ->counted()
            ->where('kind', TransactionKind::Expense)
            ->whereNull('bill_id')
            ->whereDate('date', '>=', $monthStart->toDateString())
            ->whereDate('date', '<=', $today->toDateString())
            ->selectRaw('category, custom_category_id, sum(amount) as spent')
            ->groupBy('category', 'custom_category_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [$this->key($row->category, $row->custom_category_id) => (int) $row->spent]);

        $categories = [
            ...array_map(fn (string $category): array => [$category, null, null], TransactionCategory::expenseValues()),
            ...$user->customCategories->map(fn (CustomCategory $category): array => [TransactionCategory::Other->value, $category->id, $category->name])->all(),
        ];

        return array_map(function (array $category) use ($limits, $spent, $today): array {
            [$value, $customCategoryId, $name] = $category;
            $key = $this->key($value, $customCategoryId);

            return $this->row($value, $customCategoryId, $name, $limits->get($key), $spent->get($key, 0), $today);
        }, $categories);
    }

    /**
     * What the month's limits add up to. Categories without one add nothing.
     *
     * @param  list<LimitRow>  $rows
     */
    public function totalLimits(array $rows): int
    {
        return array_sum(array_map(fn (array $row): int => $row['limit'] ?? 0, $rows));
    }

    /**
     * @return LimitRow
     */
    private function row(string $category, ?int $customCategoryId, ?string $name, ?int $limit, int $spent, CarbonImmutable $today): array
    {
        $base = ['category' => $category, 'customCategoryId' => $customCategoryId, 'categoryName' => $name, 'limit' => $limit, 'spent' => $spent];

        if ($limit === null) {
            return [...$base, 'left' => null, 'pace' => null, 'status' => 'none', 'aheadOfPace' => false];
        }

        $pace = intdiv($limit * $today->day, $today->daysInMonth);
        $status = match (true) {
            $spent > $limit => 'over',
            $spent * 100 >= $limit * self::CLOSE_PERCENT => 'close',
            default => 'under',
        };

        return [...$base, 'left' => $limit - $spent, 'pace' => $pace, 'status' => $status, 'aheadOfPace' => $status !== 'over' && $spent > $pace];
    }

    private function key(string $category, int|string|null $customCategoryId): string
    {
        return $category.':'.($customCategoryId ?? '');
    }
}
