<?php

namespace Database\Factories\Money;

use App\Models\Money\Account;
use App\Models\Money\AccountType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'type' => AccountType::Bank,
            'balance' => 1_000_000,
            'is_spendable' => true,
        ];
    }

    public function notSpendable(): static
    {
        return $this->state(['is_spendable' => false]);
    }
}
