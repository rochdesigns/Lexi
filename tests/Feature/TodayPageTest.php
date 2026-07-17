<?php

use App\Models\User;
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
            ->has('moneySummary')
            ->has('habits')
            ->has('healthMetrics')
        );
});

test('today redirects unauthenticated users to login', function (): void {
    $this->get('/today')->assertRedirect('/login');
});
