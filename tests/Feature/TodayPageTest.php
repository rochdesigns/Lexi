<?php

use App\Models\Money\Bill;
use App\Models\Money\IncomeSource;
use App\Models\User;
use App\Models\VisionBoardItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

test('today page hands out signed image urls, never public storage urls', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    VisionBoardItem::factory()->for($user)->create(['caption' => 'Only', 'position' => 1]);

    $this->actingAs($user)
        ->get('/today')
        ->assertInertia(fn ($page) => $page
            ->where('visionBoardItems.0.imageUrl', fn (string $url): bool => str_contains($url, '/image?')
                && str_contains($url, 'signature=')
                && ! str_contains($url, '/storage/'))
        );
});

test('the today money card shows safe to spend once income or bills exist', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)->get('/today')->assertInertia(fn ($page) => $page->where('moneySummary', null));

    IncomeSource::factory()->for($user)->create(['amount' => 1_000_000]);
    Bill::factory()->for($user)->create(['amount' => 200_000, 'due_day' => now()->day]);

    $this->actingAs($user)->get('/today')->assertInertia(fn ($page) => $page
        ->where('moneySummary.safeToSpend', 800_000)
        ->where('moneySummary.dueSoonCount', 1));
});
