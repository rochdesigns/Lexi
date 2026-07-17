<?php

use App\Models\Account;
use App\Models\BudgetMonth;
use App\Models\Event;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\Task;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the expected records', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'elle@example.com')->exists())->toBeTrue();
    expect(Task::count())->toBe(9);
    expect(Event::count())->toBe(3);
    expect(Habit::count())->toBe(4);
    expect(Account::count())->toBe(3);
    expect(BudgetMonth::count())->toBe(1);
    expect(HabitLog::where('done', true)->count())->toBeGreaterThanOrEqual(16);
});
