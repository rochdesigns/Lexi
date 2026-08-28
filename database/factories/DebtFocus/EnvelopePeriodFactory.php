<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnvelopePeriod> */
class EnvelopePeriodFactory extends Factory
{
    protected $model = EnvelopePeriod::class;

    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'target' => fake()->randomFloat(2, 20, 300),
            'sweep_offered_at' => null,
            'sweep_accepted' => null,
        ];
    }
}
