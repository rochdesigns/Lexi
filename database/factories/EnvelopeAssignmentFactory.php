<?php

namespace Database\Factories;

use App\Models\BudgetCategory;
use App\Models\EnvelopeAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnvelopeAssignment>
 */
class EnvelopeAssignmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'envelope_type' => 'budget_category',
            'envelope_id' => BudgetCategory::factory(),
            'cycle_start' => now()->startOfMonth()->toDateString(),
            'assigned' => fake()->randomFloat(2, 50, 1000),
        ];
    }
}
