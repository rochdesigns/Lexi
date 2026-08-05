<?php

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('creates events from valid json data', function () {
    $data = json_encode([
        ['title' => 'Team Standup', 'date' => '2026-08-01', 'time' => '09:00'],
        ['title' => 'Sprint Review', 'date' => '2026-08-05', 'time' => '14:00'],
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => $data,
    ])->assertSuccessful()->expectsOutputToContain('2 created');

    expect(Event::where('user_id', $this->user->id)->count())->toBe(2);

    $event = Event::where('title', 'Team Standup')->first();
    expect($event->tag)->toBe('Work');
    expect($event->color)->toBe('#3f7fb0');
    expect($event->date->format('Y-m-d'))->toBe('2026-08-01');
});

it('skips duplicate events', function () {
    $data = json_encode([
        ['title' => 'Team Standup', 'date' => '2026-08-01', 'time' => '09:00'],
    ]);

    $this->artisan('calendar:sync-outlook', ['--user' => $this->user->id, '--data' => $data]);
    $this->artisan('calendar:sync-outlook', ['--user' => $this->user->id, '--data' => $data])
        ->assertSuccessful()
        ->expectsOutputToContain('0 created, 0 updated, 1 skipped');

    expect(Event::where('user_id', $this->user->id)->count())->toBe(1);
});

it('fails gracefully with invalid json', function () {
    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => 'not-valid-json',
    ])->assertFailed();
});

it('fails when user does not exist', function () {
    $this->artisan('calendar:sync-outlook', [
        '--user' => 9999,
        '--data' => '[]',
    ])->assertFailed();
});

it('skips events with missing title or date', function () {
    $data = json_encode([
        ['title' => 'Valid Event', 'date' => '2026-08-01', 'time' => '09:00'],
        ['title' => '', 'date' => '2026-08-02', 'time' => '10:00'],
        ['date' => '2026-08-03', 'time' => '11:00'],
    ]);

    $this->artisan('calendar:sync-outlook', ['--user' => $this->user->id, '--data' => $data])
        ->assertSuccessful()
        ->expectsOutputToContain('1 created, 0 updated, 0 skipped. 2 malformed entries ignored.');
});

it('stores the end time when provided', function () {
    $data = json_encode([
        ['title' => 'Roch 1:1', 'date' => '2026-08-05', 'time' => '15:00', 'end_time' => '15:30'],
    ]);

    $this->artisan('calendar:sync-outlook', ['--user' => $this->user->id, '--data' => $data])
        ->assertSuccessful()
        ->expectsOutputToContain('1 created');

    expect(Event::where('title', 'Roch 1:1')->first()->end_time)->toBe('15:30');
});

it('leaves end time null when omitted', function () {
    $data = json_encode([
        ['title' => 'No End Time', 'date' => '2026-08-05', 'time' => '15:00'],
    ]);

    $this->artisan('calendar:sync-outlook', ['--user' => $this->user->id, '--data' => $data])
        ->assertSuccessful();

    expect(Event::where('title', 'No End Time')->first()->end_time)->toBeNull();
});

it('backfills a null end time on an already synced event', function () {
    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'IAG Development', 'date' => '2026-08-11', 'time' => '15:00'],
        ]),
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'IAG Development', 'date' => '2026-08-11', 'time' => '15:00', 'end_time' => '16:00'],
        ]),
    ])->assertSuccessful()->expectsOutputToContain('0 created, 1 updated, 0 skipped');

    expect(Event::where('user_id', $this->user->id)->count())->toBe(1);
    expect(Event::where('title', 'IAG Development')->first()->end_time)->toBe('16:00');
});

it('soft deletes a synced event Outlook no longer returns', function () {
    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'Kept', 'date' => '2026-08-01', 'time' => '09:00'],
            ['title' => 'Cancelled', 'date' => '2026-08-02', 'time' => '10:00'],
        ]),
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'Kept', 'date' => '2026-08-01', 'time' => '09:00'],
            ['title' => 'Filler', 'date' => '2026-08-02', 'time' => '11:00'],
        ]),
    ])->assertSuccessful()->expectsOutputToContain('1 cancelled');

    expect(Event::where('title', 'Cancelled')->exists())->toBeFalse();
    expect(Event::withTrashed()->where('title', 'Cancelled')->first()->deleted_at)->not->toBeNull();
    expect(Event::where('title', 'Kept')->exists())->toBeTrue();
});

it('hides a cancelled event from the calendar', function () {
    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'CE Innovation Center', 'date' => '2026-08-02', 'time' => '21:30'],
        ]),
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--from' => '2026-08-01',
        '--to' => '2026-08-03',
        '--data' => '[]',
    ])->assertSuccessful()->expectsOutputToContain('1 cancelled');

    $occurrences = Event::expandRecurring(
        Carbon::parse('2026-08-01'),
        Carbon::parse('2026-08-03'),
        $this->user->id,
    );

    expect($occurrences)->toBeEmpty();
});

it('never prunes events the user created by hand', function () {
    Event::create([
        'user_id' => $this->user->id,
        'title' => 'My Own Work Event',
        'date' => '2026-08-02',
        'time' => '10:00',
        'tag' => 'Work',
        'color' => '#3f7fb0',
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'Synced', 'date' => '2026-08-01', 'time' => '09:00'],
            ['title' => 'Synced Later', 'date' => '2026-08-03', 'time' => '09:00'],
        ]),
    ])->assertSuccessful();

    expect(Event::where('title', 'My Own Work Event')->exists())->toBeTrue();
});

it('does not prune outside the payload date range', function () {
    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'Far Future', 'date' => '2026-09-30', 'time' => '09:00'],
        ]),
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'This Week', 'date' => '2026-08-01', 'time' => '09:00'],
        ]),
    ])->assertSuccessful();

    expect(Event::where('title', 'Far Future')->exists())->toBeTrue();
});

it('does not prune on an empty payload without an explicit window', function () {
    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'Survivor', 'date' => '2026-08-01', 'time' => '09:00'],
        ]),
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => '[]',
    ])->assertSuccessful();

    expect(Event::where('title', 'Survivor')->exists())->toBeTrue();
});

it('honours --no-prune', function () {
    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'Kept', 'date' => '2026-08-01', 'time' => '09:00'],
            ['title' => 'Would Be Cancelled', 'date' => '2026-08-02', 'time' => '10:00'],
        ]),
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--no-prune' => true,
        '--data' => json_encode([
            ['title' => 'Kept', 'date' => '2026-08-01', 'time' => '09:00'],
            ['title' => 'Filler', 'date' => '2026-08-02', 'time' => '11:00'],
        ]),
    ])->assertSuccessful();

    expect(Event::where('title', 'Would Be Cancelled')->exists())->toBeTrue();
});

it('restores a cancelled event that Outlook reinstates', function () {
    $payload = json_encode([
        ['title' => 'Reinstated', 'date' => '2026-08-02', 'time' => '10:00'],
    ]);

    $this->artisan('calendar:sync-outlook', ['--user' => $this->user->id, '--data' => $payload]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--from' => '2026-08-01',
        '--to' => '2026-08-03',
        '--data' => '[]',
    ]);

    expect(Event::where('title', 'Reinstated')->exists())->toBeFalse();

    $this->artisan('calendar:sync-outlook', ['--user' => $this->user->id, '--data' => $payload])
        ->assertSuccessful()
        ->expectsOutputToContain('0 created, 0 updated, 0 skipped. 1 restored.');

    expect(Event::where('title', 'Reinstated')->count())->toBe(1);
});

it('marks an already synced event as outlook sourced', function () {
    Event::create([
        'user_id' => $this->user->id,
        'title' => 'Synced Before Source Existed',
        'date' => '2026-08-02',
        'time' => '10:00',
        'tag' => 'Work',
        'color' => '#3f7fb0',
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'Synced Before Source Existed', 'date' => '2026-08-02', 'time' => '10:00'],
        ]),
    ])->assertSuccessful();

    expect(Event::where('title', 'Synced Before Source Existed')->first()->source)->toBe('outlook');
});

it('does not overwrite an existing end time', function () {
    Event::create([
        'user_id' => $this->user->id,
        'title' => 'Hand Edited',
        'date' => '2026-08-11',
        'time' => '15:00',
        'end_time' => '17:00',
        'tag' => 'Work',
        'color' => '#3f7fb0',
    ]);

    $this->artisan('calendar:sync-outlook', [
        '--user' => $this->user->id,
        '--data' => json_encode([
            ['title' => 'Hand Edited', 'date' => '2026-08-11', 'time' => '15:00', 'end_time' => '16:00'],
        ]),
    ])->assertSuccessful()->expectsOutputToContain('0 created, 0 updated, 1 skipped');

    expect(Event::where('title', 'Hand Edited')->first()->end_time)->toBe('17:00');
});
