<?php

namespace Database\Factories;

use App\Models\SinkingFund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SinkingFund>
 */
class SinkingFundFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $pairs = [
            ['color' => '#3f7fb0', 'bg' => '#dcebf5', 'icon' => 'directions_car'],
            ['color' => '#a85fc0', 'bg' => '#efe0f5', 'icon' => 'subscriptions'],
            ['color' => '#2f7d5b', 'bg' => '#dff3e6', 'icon' => 'shield'],
            ['color' => '#d98a2b', 'bg' => '#f8ecd6', 'icon' => 'confirmation_number'],
            ['color' => '#c0512c', 'bg' => '#fbe2d6', 'icon' => 'health_and_safety'],
        ];
        $pair = fake()->randomElement($pairs);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'icon' => $pair['icon'],
            'color' => $pair['color'],
            'bg' => $pair['bg'],
            'saved' => fake()->randomFloat(2, 0, 500),
            'target' => fake()->randomFloat(2, 500, 2000),
            'per_month' => fake()->randomFloat(2, 20, 200),
            'due_date' => fake()->optional()->dateTimeBetween('+1 month', '+2 years')?->format('Y-m-d'),
            'position' => 0,
        ];
    }
}
