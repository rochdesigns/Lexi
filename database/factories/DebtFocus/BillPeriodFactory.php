<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BillPeriod> */
class BillPeriodFactory extends Factory
{
    protected $model = BillPeriod::class;

    public function definition(): array
    {
        return [
            'bill_id' => Bill::factory(),
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'expected_amount' => fake()->randomFloat(2, 20, 300),
            'actual_amount' => null,
            'paid_at' => null,
        ];
    }
}
