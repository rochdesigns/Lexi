<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'expense',
            'account_id' => Account::factory(),
            'from_account_id' => null,
            'to_account_id' => null,
            'amount' => fake()->randomFloat(2, 5, 500),
            'description' => fake()->words(2, true),
            'occurred_at' => now()->toDateString(),
        ];
    }
}
