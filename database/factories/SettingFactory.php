<?php

namespace Database\Factories;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'theme' => 'ocean',
            'budget_cycle_days' => 30,
            'budget_cycle_anchor' => now()->toDateString(),
            'cycle_type' => 'fixed_days',
            'monthly_start_day' => null,
        ];
    }
}
