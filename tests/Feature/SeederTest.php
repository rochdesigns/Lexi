<?php

use App\Models\Event;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HealthMetric;
use App\Models\HealthWeek;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskSection;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the expected records', function (): void {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'elle@example.com')->first();

    expect($user)->not->toBeNull()
        ->and(Setting::where('user_id', $user->id)->count())->toBe(1)
        ->and(TaskSection::count())->toBe(3)
        ->and(Task::count())->toBe(9)
        ->and(Event::count())->toBe(3)
        ->and(Habit::count())->toBe(4)
        ->and(HealthMetric::count())->toBe(4)
        ->and(HealthWeek::count())->toBe(7)
        ->and(HabitLog::where('done', true)->count())->toBeGreaterThanOrEqual(16);
});
