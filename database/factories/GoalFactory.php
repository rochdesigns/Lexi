<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $pairs = [
            ['color' => '#3f7fb0', 'bg' => '#dcebf5', 'icon' => 'flight'],
            ['color' => '#a85fc0', 'bg' => '#efe0f5', 'icon' => 'laptop_mac'],
            ['color' => '#2f7d5b', 'bg' => '#dff3e6', 'icon' => 'health_and_safety'],
            ['color' => '#d98a2b', 'bg' => '#f8ecd6', 'icon' => 'home'],
            ['color' => '#c0512c', 'bg' => '#fbe2d6', 'icon' => 'directions_car'],
            ['color' => '#6b6355', 'bg' => '#eee9dd', 'icon' => 'school'],
        ];
        $pair = fake()->randomElement($pairs);

        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'icon' => $pair['icon'],
            'color' => $pair['color'],
            'bg' => $pair['bg'],
            'saved' => fake()->randomFloat(2, 0, 5000),
            'target' => fake()->randomFloat(2, 1000, 20000),
            'per_month' => fake()->randomFloat(2, 100, 1000),
            'by_date' => fake()->optional()->dateTimeBetween('+3 months', '+3 years')?->format('Y-m-d'),
            'position' => 0,
        ];
    }
}
