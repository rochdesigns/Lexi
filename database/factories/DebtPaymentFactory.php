<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Debt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DebtPaymentFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 500, 8000);
        $interest = round($amount * fake()->randomFloat(2, 0.05, 0.4), 2);

        return [
            'user_id' => User::factory(),
            'debt_id' => Debt::factory(),
            'transaction_id' => Transaction::factory(),
            'account_id' => Account::factory(),
            'amount' => $amount,
            'principal_amount' => round($amount - $interest, 2),
            'interest_amount' => $interest,
            'paid_at' => fake()->dateTimeBetween('-11 months', 'now')->format('Y-m-d'),
        ];
    }
}
