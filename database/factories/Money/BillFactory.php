<?php

namespace Database\Factories\Money;

use App\Models\Money\Bill;
use App\Models\Money\BillFrequency;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bill>
 */
class BillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'amount' => 150_000,
            'frequency' => BillFrequency::Monthly,
            'due_day' => 5,
            'due_month' => null,
            'autopay' => false,
        ];
    }

    public function annual(int $month = 3): static
    {
        return $this->state([
            'frequency' => BillFrequency::Annual,
            'due_month' => $month,
        ]);
    }
}
