<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\VisionBoardItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisionBoardItem>
 */
class VisionBoardItemFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'image_path' => 'vision-board/'.fake()->uuid().'.jpg',
            'caption' => fake()->sentence(3),
            'position' => 0,
        ];
    }
}
