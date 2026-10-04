<?php

namespace Database\Factories\Money;

use App\Models\Money\Account;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionCategory;
use App\Models\Money\TransactionSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Paid from an account by default. Use onCard() for a card charge.
 *
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => now()->toDateString(),
            'amount' => 25_000,
            'category' => TransactionCategory::Food,
            'note' => null,
            'source' => TransactionSource::Manual,
            'debt_id' => null,
            'account_id' => Account::factory(),
            'matched_transaction_id' => null,
        ];
    }

    public function onCard(?Debt $card = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_id' => null,
            'debt_id' => $card === null ? Debt::factory()->state(['type' => DebtType::CreditCard, 'user_id' => $attributes['user_id']]) : $card->id,
        ]);
    }
}
