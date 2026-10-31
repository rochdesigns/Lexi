<?php

namespace Database\Factories\Money;

use App\Models\Money\CustomCategory;
use App\Models\Money\SpendingLimit;
use App\Models\Money\TransactionCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpendingLimit>
 */
class SpendingLimitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => TransactionCategory::Groceries,
            'custom_category_id' => null,
            'amount' => 800_000,
            'starts_on' => now()->startOfMonth()->toDateString(),
        ];
    }

    /**
     * A limit on one of the user's own categories.
     */
    public function custom(CustomCategory $category): static
    {
        return $this->state([
            'user_id' => $category->user_id,
            'category' => TransactionCategory::Other,
            'custom_category_id' => $category->id,
        ]);
    }
}
