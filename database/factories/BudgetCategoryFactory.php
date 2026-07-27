<?php

namespace Database\Factories;

use App\Models\BudgetCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetCategory>
 */
class BudgetCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = [
            ['name' => 'Groceries', 'icon' => 'shopping_cart', 'color' => '#2f7d5b'],
            ['name' => 'Dining out', 'icon' => 'restaurant', 'color' => '#d98a2b'],
            ['name' => 'Transport', 'icon' => 'directions_car', 'color' => '#3f7fb0'],
            ['name' => 'Entertainment', 'icon' => 'movie', 'color' => '#a85fc0'],
            ['name' => 'Health', 'icon' => 'favorite', 'color' => '#c0512c'],
        ];
        $cat = fake()->randomElement($categories);

        return [
            'user_id' => User::factory(),
            'name' => $cat['name'],
            'icon' => $cat['icon'],
            'color' => $cat['color'],
            'limit_amount' => fake()->randomFloat(2, 500, 5000),
        ];
    }
}
