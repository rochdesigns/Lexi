<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Debt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Debt> */
class DebtFactory extends Factory
{
    protected $model = Debt::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'balance' => fake()->randomFloat(2, 100, 20000),
            'apr' => fake()->randomFloat(2, 0, 30),
            'minimum_payment' => fake()->randomFloat(2, 20, 500),
            'debt_type' => fake()->randomElement(['revolving', 'installment']),
        ];
    }
}
