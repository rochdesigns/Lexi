<?php

namespace Database\Factories;

use App\Models\Debt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DebtBalanceSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'debt_id' => Debt::factory(),
            'balance' => fake()->randomFloat(2, 0, 200000),
            'snapshot_date' => fake()->unique()->dateTimeBetween('-11 months', 'now')->format('Y-m-d'),
        ];
    }
}
