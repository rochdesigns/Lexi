<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'date' => fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
            'time' => fake()->time('H:i'),
            'tag' => fake()->randomElement(['work', 'personal', 'health', 'finance']),
            'color' => fake()->randomElement(['#2f7d5b', '#3f7fb0', '#a85fc0', '#d98a2b', '#c0512c']),
            'recurrence_type' => null,
            'recurrence_interval' => 1,
            'recurrence_days' => null,
            'recurrence_ends_on' => null,
        ];
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
