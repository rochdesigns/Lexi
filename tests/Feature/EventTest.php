<?php

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

// --- bg accessor ---

test('bg accessor returns correct light shade for each color', function (string $color, string $expectedBg): void {
    $event = Event::factory()->for($this->user)->create(['color' => $color]);
    expect($event->bg)->toBe($expectedBg);
})->with([
    ['#2f7d5b', '#dff3e6'],
    ['#3f7fb0', '#dcebf5'],
    ['#a85fc0', '#efe0f5'],
    ['#d98a2b', '#f8ecd6'],
    ['#c0512c', '#fbe2d6'],
    ['#unknown', '#eee9dd'],
]);

// --- expandRecurring: non-recurring ---

test('expandRecurring returns a non-recurring event within range', function (): void {
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-07-31');

    Event::factory()->for($this->user)->create(['date' => '2026-07-15', 'title' => 'Meeting']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    expect($result)->toHaveCount(1)
        ->and($result[0]['date'])->toBe('2026-07-15')
        ->and($result[0]['title'])->toBe('Meeting')
        ->and($result[0]['is_recurring'])->toBeFalse();
});

test('expandRecurring excludes a non-recurring event outside range', function (): void {
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-07-31');

    Event::factory()->for($this->user)->create(['date' => '2026-08-10']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    expect($result)->toHaveCount(0);
});

test('expandRecurring excludes events belonging to another user', function (): void {
    $other = User::factory()->create();
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-07-31');

    Event::factory()->for($other)->create(['date' => '2026-07-10']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    expect($result)->toHaveCount(0);
});

// --- expandRecurring: daily ---

test('expandRecurring expands daily recurrence across a range', function (): void {
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-07-05');

    Event::factory()->for($this->user)->daily()->create(['date' => '2026-07-01', 'recurrence_interval' => 2]);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    $dates = array_column($result, 'date');
    expect($dates)->toBe(['2026-07-01', '2026-07-03', '2026-07-05']);
});

test('expandRecurring respects recurrence_ends_on for daily events', function (): void {
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-07-31');

    Event::factory()->for($this->user)->daily()->endsOn('2026-07-03')->create(['date' => '2026-07-01']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    $dates = array_column($result, 'date');
    expect($dates)->toBe(['2026-07-01', '2026-07-02', '2026-07-03']);
});

test('expandRecurring daily event starting before range only includes occurrences within range', function (): void {
    $start = Carbon::parse('2026-07-10');
    $end = Carbon::parse('2026-07-12');

    Event::factory()->for($this->user)->daily()->create(['date' => '2026-07-01']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    $dates = array_column($result, 'date');
    expect($dates)->toBe(['2026-07-10', '2026-07-11', '2026-07-12']);
});

// --- expandRecurring: weekly ---

test('expandRecurring expands weekly recurrence', function (): void {
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-07-31');

    Event::factory()->for($this->user)->weekly()->create(['date' => '2026-07-01']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    $dates = array_column($result, 'date');
    expect($dates)->toBe(['2026-07-01', '2026-07-08', '2026-07-15', '2026-07-22', '2026-07-29']);
});

test('expandRecurring expands weekly recurrence with specific days', function (): void {
    $start = Carbon::parse('2026-07-06'); // Monday
    $end = Carbon::parse('2026-07-12'); // Sunday

    Event::factory()->for($this->user)->weekly(['Mon', 'Wed', 'Fri'])->create(['date' => '2026-07-06']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    $dates = array_column($result, 'date');
    expect($dates)->toBe(['2026-07-06', '2026-07-08', '2026-07-10']);
});

// --- expandRecurring: monthly ---

test('expandRecurring expands monthly recurrence', function (): void {
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-10-31');

    Event::factory()->for($this->user)->monthly()->create(['date' => '2026-07-15']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    $dates = array_column($result, 'date');
    expect($dates)->toBe(['2026-07-15', '2026-08-15', '2026-09-15', '2026-10-15']);
});

// --- expandRecurring: yearly ---

test('expandRecurring expands yearly recurrence', function (): void {
    $start = Carbon::parse('2026-01-01');
    $end = Carbon::parse('2028-12-31');

    Event::factory()->for($this->user)->yearly()->create(['date' => '2026-06-01']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    $dates = array_column($result, 'date');
    expect($dates)->toBe(['2026-06-01', '2027-06-01', '2028-06-01']);
});

// --- expandRecurring: sorting ---

test('expandRecurring sorts occurrences by date and time', function (): void {
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-07-02');

    Event::factory()->for($this->user)->create(['date' => '2026-07-02', 'time' => '09:00', 'title' => 'B']);
    Event::factory()->for($this->user)->create(['date' => '2026-07-01', 'time' => '14:00', 'title' => 'C']);
    Event::factory()->for($this->user)->create(['date' => '2026-07-01', 'time' => '08:00', 'title' => 'A']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    expect($result[0]['title'])->toBe('A')
        ->and($result[1]['title'])->toBe('C')
        ->and($result[2]['title'])->toBe('B');
});

// --- toOccurrence shape ---

test('expandRecurring occurrence includes expected keys', function (): void {
    $start = Carbon::parse('2026-07-01');
    $end = Carbon::parse('2026-07-31');

    Event::factory()->for($this->user)->create(['date' => '2026-07-10', 'color' => '#3f7fb0']);

    $result = Event::expandRecurring($start, $end, $this->user->id);

    expect($result[0])->toHaveKeys(['id', 'title', 'date', 'time', 'tag', 'color', 'bg', 'is_recurring', 'recurrence_type'])
        ->and($result[0]['bg'])->toBe('#dcebf5')
        ->and($result[0]['is_recurring'])->toBeFalse();
});
