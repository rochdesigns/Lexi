<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'institution' => fake()->company(),
            'type' => fake()->randomElement(['checking', 'savings', 'cash']),
            'is_liability' => false,
            'balance' => fake()->randomFloat(2, 0, 5000),
        ];
    }

    public function liability(): static
    {
        return $this->state(fn () => [
            'type' => 'credit',
            'is_liability' => true,
        ]);
    }
}
