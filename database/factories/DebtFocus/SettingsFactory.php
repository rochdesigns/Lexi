<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Settings;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Settings> */
class SettingsFactory extends Factory
{
    protected $model = Settings::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'strategy' => 'avalanche',
            'extra_monthly' => 0,
        ];
    }
}
