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
            'monthly_rate' => fake()->randomFloat(2, 0, 2.5),
            'minimum_payment' => fake()->randomFloat(2, 20, 500),
            'debt_type' => fake()->randomElement(['revolving', 'installment']),
            // Derived, not random: a revolving debt is a card, everything else amortises.
            'kind' => fn (array $attributes) => $attributes['debt_type'] === 'revolving' ? 'card' : 'loan',
            'due_day' => fake()->numberBetween(1, 28),
        ];
    }
}
