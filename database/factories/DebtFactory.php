<?php

namespace Database\Factories;

use App\Models\Debt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Debt>
 */
class DebtFactory extends Factory
{
    public function definition(): array
    {
        $original = $this->faker->randomElement([5000, 10000, 15000, 20000]);
        $balance = $this->faker->numberBetween(500, $original);

        return [
            'budget_category_id' => null,
            'name' => $this->faker->words(3, true),
            'icon' => $this->faker->randomElement(['credit_card', 'directions_car', 'smartphone', 'chair']),
            'color' => $this->faker->randomElement(['#3f7fb0', '#a85fc0', '#d98a2b', '#c0512c']),
            'bg' => $this->faker->randomElement(['#dcebf5', '#efe0f5', '#f8ecd6', '#fbe2d6']),
            'balance' => $balance,
            'original' => $original,
            'per_month' => $this->faker->randomElement([100, 200, 300, 500]),
            'interest_rate' => null,
            'interest_rate_period' => 'annual',
            'due_day' => $this->faker->numberBetween(1, 28),
            'position' => 0,
        ];
    }
}
