<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

it('exports application data and skips framework tables', function () {
    User::factory()->create(['email' => 'me@example.com']);
    DB::table('cache')->insert(['key' => 'k', 'value' => 'v', 'expiration' => 1]);

    $this->artisan('data:export')->assertSuccessful();

    $data = json_decode(Storage::disk('local')->get('data-export.json'), true);

    expect($data['users'])->toHaveCount(1)
        ->and($data['users'][0]['email'])->toBe('me@example.com')
        ->and($data)->not->toHaveKeys(['cache', 'migrations', 'sessions']);
});

it('round-trips data into an empty database', function () {
    $user = User::factory()->create(['email' => 'me@example.com']);
    $this->artisan('data:export')->assertSuccessful();

    DB::table('users')->delete();

    $this->artisan('data:import')->assertSuccessful();

    expect(User::query()->whereKey($user->id)->value('email'))->toBe('me@example.com');
});

it('refuses to import into populated tables without --force', function () {
    User::factory()->create();
    $this->artisan('data:export')->assertSuccessful();

    $this->artisan('data:import')->assertFailed();
});

it('fails when the export file is missing', function () {
    $this->artisan('data:import', ['path' => 'missing.json'])->assertFailed();
});
