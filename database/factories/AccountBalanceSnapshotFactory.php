<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountBalanceSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => Account::factory(),
            'balance' => fake()->randomFloat(2, 0, 50000),
            'snapshot_date' => fake()->unique()->dateTimeBetween('-11 months', 'now')->format('Y-m-d'),
        ];
    }
}
