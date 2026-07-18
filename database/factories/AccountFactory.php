<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Checking', 'Savings', 'Credit Card']).' Account',
            'institution' => fake()->company(),
            'last4' => fake()->numerify('####'),
            'balance' => fake()->randomFloat(2, 100, 20000),
            'type' => fake()->randomElement(['cash', 'credit', 'investment']),
        ];
    }
}
