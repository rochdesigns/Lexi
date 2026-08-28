<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Bill;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bill> */
class BillFactory extends Factory
{
    protected $model = Bill::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'expected_amount' => fake()->randomFloat(2, 20, 300),
            'due_day' => fake()->numberBetween(1, 28),
            'active' => true,
        ];
    }
}
