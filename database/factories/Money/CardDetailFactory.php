<?php

namespace Database\Factories\Money;

use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CardDetail>
 */
class CardDetailFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'debt_id' => Debt::factory()->state([
                'type' => DebtType::CreditCard,
                'interest_type' => InterestType::Revolving,
                'monthly_interest_rate' => 3.5,
                'due_day' => 28,
            ]),
            'credit_limit' => 10_000_000,
            'statement_day' => 10,
            'min_payment_percent' => 3,
            'min_payment_floor' => 50_000,
            'revolving_balance' => 0,
        ];
    }
}
