<?php

namespace Database\Factories\Money;

use App\Models\Money\Debt;
use App\Models\Money\DebtStatus;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Debt>
 */
class DebtFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'type' => DebtType::Loan,
            'lender' => fake()->company(),
            'interest_type' => InterestType::None,
            'monthly_interest_rate' => null,
            'due_day' => 15,
            'status' => DebtStatus::Active,
            'notes' => null,
            'outstanding_balance' => null,
            'target_payoff_date' => null,
        ];
    }
}
