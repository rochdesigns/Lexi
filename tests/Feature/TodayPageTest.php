<?php

use App\Models\BudgetCategory;
use App\Models\RecurringBill;
use App\Models\Setting;
use App\Models\User;
use App\Models\VisionBoardItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

test('today page loads for authenticated user', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->get('/today')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->has('user')
            ->has('todayEvents')
            ->has('todayTasks')
            ->has('moneySummary')
            ->has('habits')
            ->has('healthMetrics')
        );
});

test('today redirects unauthenticated users to login', function (): void {
    $this->get('/today')->assertRedirect('/login');
});

test('today page includes vision board items ordered by position', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    VisionBoardItem::factory()->for($user)->create(['caption' => 'Second', 'position' => 2]);
    VisionBoardItem::factory()->for($user)->create(['caption' => 'First', 'position' => 1]);

    $this->actingAs($user)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->component('Today')
            ->where('visionBoardItems.0.caption', 'First')
            ->where('visionBoardItems.1.caption', 'Second')
        );
});

test('the money card includes bills due within the next 7 days', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    $category = BudgetCategory::factory()->for($user)->create();

    RecurringBill::factory()->for($user)->create([
        'name' => 'Internet', 'day_of_month' => Carbon::now()->addDays(2)->day, 'budget_category_id' => $category->id,
    ]);

    $this->actingAs($user)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->has('upcomingBills', 1)
            ->where('upcomingBills.0.name', 'Internet')
        );
});
