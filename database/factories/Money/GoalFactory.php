<?php

namespace Database\Factories\Money;

use App\Models\Money\Goal;
use App\Models\Money\GoalKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A sinking fund by default. Use buffer() for the emergency buffer.
 *
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'kind' => GoalKind::Sinking,
            'name' => fake()->words(2, true),
            'monthly_contribution' => 100_000,
            'saved' => 0,
            'buffer_months' => null,
            'target_amount' => 1_200_000,
            'target_date' => null,
            'account_id' => null,
        ];
    }

    public function buffer(int $months = 3): static
    {
        return $this->state([
            'kind' => GoalKind::Buffer,
            'name' => 'Emergency buffer',
            'buffer_months' => $months,
            'target_amount' => null,
            'target_date' => null,
        ]);
    }
}
