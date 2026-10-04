<?php

namespace Database\Factories\Money;

use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtPayment>
 */
class DebtPaymentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'debt_id' => Debt::factory(),
            'payment_plan_id' => null,
            'date' => now()->toDateString(),
            'amount' => 100_000,
            'is_extra' => false,
        ];
    }
}
