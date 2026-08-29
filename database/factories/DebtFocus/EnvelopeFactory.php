<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Envelope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Envelope> */
class EnvelopeFactory extends Factory
{
    protected $model = Envelope::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'icon' => null,
            'default_target' => fake()->randomFloat(2, 20, 300),
            'active' => true,
        ];
    }
}
