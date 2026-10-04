<?php

namespace Database\Factories\Money;

use App\Models\Money\IncomeSchedule;
use App\Models\Money\IncomeSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncomeSource>
 */
class IncomeSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'amount' => 3_000_000,
            'schedule' => IncomeSchedule::Monthly,
            'pay_day_1' => 30,
            'pay_day_2' => null,
        ];
    }

    public function semiMonthly(int $first = 15, int $second = 30): static
    {
        return $this->state([
            'schedule' => IncomeSchedule::SemiMonthly,
            'pay_day_1' => $first,
            'pay_day_2' => $second,
        ]);
    }
}
