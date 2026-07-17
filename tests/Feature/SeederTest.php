<?php

test('seeder file contains correct DatabaseSeeder implementation', function () {
    $seederPath = app_path('../database/seeders/DatabaseSeeder.php');
    expect(file_exists($seederPath))->toBeTrue();

    $content = file_get_contents($seederPath);

    // Check that it contains the required models
    expect($content)->toContain('use App\\Models\\User;');
    expect($content)->toContain('use App\\Models\\Setting;');
    expect($content)->toContain('use App\\Models\\Event;');
    expect($content)->toContain('use App\\Models\\Task;');
    expect($content)->toContain('use App\\Models\\HealthMetric;');
    expect($content)->toContain('use App\\Models\\HealthWeek;');
    expect($content)->toContain('use App\\Models\\Habit;');
    expect($content)->toContain('use App\\Models\\HabitLog;');
    expect($content)->toContain('use App\\Models\\Account;');
    expect($content)->toContain('use App\\Models\\BudgetMonth;');

    // Check that it creates Elle Domingo user
    expect($content)->toContain("'name' => 'Elle Domingo'");
    expect($content)->toContain("'email' => 'elle@example.com'");

    // Check that it creates events
    expect($content)->toContain('Team standup');
    expect($content)->toContain('Lunch with Mia');
    expect($content)->toContain('Yoga class');

    // Check that it creates tasks
    expect($content)->toContain('Reply to the landlord');
    expect($content)->toContain('Finish the Q3 deck');

    // Check that it creates the budget month
    expect($content)->toContain("'year' => 2026");
    expect($content)->toContain("'month' => 7");
    expect($content)->toContain("'budget' => 3200.00");

    // Check that it has proper structure
    expect($content)->toContain('public function run(): void');
});
