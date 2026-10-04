<?php

namespace Database\Factories\Money;

use App\Models\Money\Debt;
use App\Models\Money\DebtStatus;
use App\Models\Money\PaymentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentPlan>
 */
class PaymentPlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'debt_id' => Debt::factory(),
            'description' => fake()->words(2, true),
            'principal' => 1_200_000,
            'monthly_amount' => 100_000,
            'total_months' => 12,
            'start_month' => now()->startOfMonth()->toDateString(),
            'interest_type' => null,
            'in_minimum' => false,
            'status' => DebtStatus::Active,
        ];
    }
}
