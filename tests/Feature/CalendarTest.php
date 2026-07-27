<?php

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

test('guest is redirected to login', function (): void {
    $this->get('/calendar')->assertRedirect('/login');
});

test('calendar page renders with correct component and props', function (): void {
    $user = User::where('email', 'elle@example.com')->first();

    $this->actingAs($user)
        ->get('/calendar')
        ->assertInertia(fn ($page) => $page
            ->component('Calendar')
            ->has('events')
            ->has('currentMonth')
        );
});

test('calendar returns events for the current month range', function (): void {
    $user = User::factory()->create();
    $today = Carbon::today();

    Event::factory()->create([
        'user_id' => $user->id,
        'date' => $today->toDateString(),
        'title' => 'My Event',
    ]);

    $this->actingAs($user)
        ->get('/calendar?month='.$today->format('Y-m'))
        ->assertInertia(fn ($page) => $page
            ->component('Calendar')
            ->where('currentMonth', $today->format('Y-m'))
            ->has('events', 1)
            ->where('events.0.title', 'My Event')
        );
});

test('calendar does not return another users events', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();

    Event::factory()->create(['user_id' => $other->id, 'date' => Carbon::today()->toDateString()]);

    $this->actingAs($user)
        ->get('/calendar?month='.Carbon::today()->format('Y-m'))
        ->assertInertia(fn ($page) => $page->has('events', 0));
});

test('recurring event is expanded within the range', function (): void {
    $user = User::factory()->create();

    // Weekly Mon/Wed/Fri event starting on a Monday
    $monday = Carbon::parse('next Monday');
    Event::factory()->recurring()->create([
        'user_id' => $user->id,
        'date' => $monday->toDateString(),
        'title' => 'Standup',
    ]);

    $response = $this->actingAs($user)
        ->get('/calendar?month='.$monday->format('Y-m'))
        ->assertInertia(fn ($page) => $page->component('Calendar'));

    $events = $response->original->getData()['page']['props']['events'];

    // Within one month, a Mon/Wed/Fri weekly pattern should produce multiple occurrences
    expect(count(array_filter($events, fn ($e) => $e['title'] === 'Standup')))->toBeGreaterThan(1);
});

test('recurring event does not appear beyond recurrence_ends_on', function (): void {
    $user = User::factory()->create();

    $startOfMonth = Carbon::now()->startOfMonth();
    Event::factory()->create([
        'user_id' => $user->id,
        'date' => $startOfMonth->toDateString(),
        'title' => 'Ends soon',
        'recurrence_type' => 'daily',
        'recurrence_interval' => 1,
        'recurrence_ends_on' => $startOfMonth->copy()->addDays(2)->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->get('/calendar?month='.$startOfMonth->format('Y-m'));

    $events = $response->original->getData()['page']['props']['events'];
    $matching = array_filter($events, fn ($e) => $e['title'] === 'Ends soon');

    // Should appear on day 1, 2, and 3 only (3 occurrences)
    expect(count($matching))->toBe(3);
});
