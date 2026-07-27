<?php

namespace Database\Factories;

use App\Models\RecurringBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringBill>
 */
class RecurringBillFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'icon' => 'receipt',
            'color' => '#3f7fb0',
            'bg' => '#dcebf5',
            'amount' => $this->faker->randomFloat(2, 5, 500),
            'day_of_month' => $this->faker->numberBetween(1, 28),
            'budget_category_id' => null,
        ];
    }
}
