<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->randomElement(['Checking', 'Savings', 'Credit Card']).' Account',
            'institution' => fake()->company(),
            'last4' => fake()->numerify('####'),
            'balance' => fake()->randomFloat(2, 100, 20000),
            'cost_basis' => null,
            'type' => fake()->randomElement(['cash', 'credit', 'investment']),
            'is_emergency_fund' => false,
        ];
    }

    public function investment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'investment',
            'name' => fake()->randomElement(['Index Fund', 'Brokerage', 'Retirement']).' Account',
            'balance' => fake()->randomFloat(2, 20000, 500000),
            'cost_basis' => fake()->randomFloat(2, 15000, 400000),
            'is_emergency_fund' => false,
        ]);
    }

    public function emergencyFund(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'cash',
            'name' => 'Emergency Fund',
            'is_emergency_fund' => true,
        ]);
    }
}
