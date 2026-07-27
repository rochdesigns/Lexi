<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EventFactory extends Factory
{
    public function definition(): array
    {
        $colors = ['#2f7d5b', '#3f7fb0', '#a85fc0', '#d98a2b', '#c0512c', '#6b6355'];
        $tags = ['Work', 'Personal', 'Health', 'Family', 'Other'];

        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'date' => fake()->dateTimeBetween('now', '+30 days')->format('Y-m-d'),
            'time' => fake()->time('H:i'),
            'tag' => fake()->randomElement($tags),
            'color' => fake()->randomElement($colors),
            'recurrence_type' => null,
            'recurrence_interval' => 1,
            'recurrence_days' => null,
            'recurrence_ends_on' => null,
        ];
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'recurrence_type' => 'weekly',
            'recurrence_interval' => 1,
            'recurrence_days' => ['Mon', 'Wed', 'Fri'],
            'recurrence_ends_on' => null,
        ]);
    }

    public function daily(int $interval = 1): static
    {
        return $this->state([
            'recurrence_type' => 'daily',
            'recurrence_interval' => $interval,
        ]);
    }

    public function weekly(?array $days = null, int $interval = 1): static
    {
        return $this->state([
            'recurrence_type' => 'weekly',
            'recurrence_interval' => $interval,
            'recurrence_days' => $days,
        ]);
    }

    public function monthly(int $interval = 1): static
    {
        return $this->state([
            'recurrence_type' => 'monthly',
            'recurrence_interval' => $interval,
        ]);
    }

    public function yearly(int $interval = 1): static
    {
        return $this->state([
            'recurrence_type' => 'yearly',
            'recurrence_interval' => $interval,
        ]);
    }

    public function endsOn(string $date): static
    {
        return $this->state(['recurrence_ends_on' => $date]);
    }
}
