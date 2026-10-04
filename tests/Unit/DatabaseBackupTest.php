<?php

use App\Services\DatabaseBackup;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Backup and restore drill
|--------------------------------------------------------------------------
|
| These live in Unit rather than Feature on purpose: RefreshDatabase wraps
| each test in a transaction, and a snapshot taken inside a transaction is
| not a snapshot. Each test here provisions a real file-backed SQLite
| database so the dump and restore paths run exactly as they do in
| production.
|
*/

beforeEach(function (): void {
    $this->databaseFile = sys_get_temp_dir().'/lexi-backup-test-'.bin2hex(random_bytes(6)).'.sqlite';
    touch($this->databaseFile);

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $this->databaseFile,
        'backup.path' => 'backups',
        'backup.retain_days' => 30,
    ]);

    DB::purge('sqlite');
    Storage::fake(config('backup.disk'));

    Schema::create('widgets', function ($table): void {
        $table->id();
        $table->string('name');
    });

    Schema::create('gadgets', function ($table): void {
        $table->id();
        $table->foreignId('widget_id')->constrained();
    });
});

afterEach(function (): void {
    DB::purge('sqlite');

    if (is_file($this->databaseFile)) {
        unlink($this->databaseFile);
    }
});

/**
 * @param  int  $count  How many widgets to insert.
 */
function seedWidgets(int $count): void
{
    foreach (range(1, $count) as $i) {
        DB::table('widgets')->insert(['name' => "widget {$i}"]);
    }
}

test('a backup is written to the configured disk', function () {
    seedWidgets(3);

    $path = app(DatabaseBackup::class)->create();

    Storage::disk(config('backup.disk'))->assertExists($path);

    expect($path)->toStartWith('backups/')
        ->and(Storage::disk(config('backup.disk'))->size($path))->toBeGreaterThan(0);
});

test('the dump is a real sqlite database, not a row listing', function () {
    seedWidgets(1);

    $path = app(DatabaseBackup::class)->create();

    expect(Storage::disk(config('backup.disk'))->get($path))
        ->toStartWith('SQLite format 3');
});

test('a backup restores with matching row counts', function () {
    seedWidgets(4);
    DB::table('gadgets')->insert(['widget_id' => 1]);

    $backup = app(DatabaseBackup::class);
    $result = $backup->verifyRestore($backup->create());

    expect($result['missing'])->toBe([])
        ->and($result['drifted'])->toBe([])
        ->and($result['tables']['widgets'])->toBe(['expected' => 4, 'restored' => 4])
        ->and($result['tables']['gadgets'])->toBe(['expected' => 1, 'restored' => 1]);
});

test('verifying a restore leaves the live database untouched', function () {
    seedWidgets(2);

    $backup = app(DatabaseBackup::class);
    $backup->verifyRestore($backup->create());

    expect(DB::table('widgets')->count())->toBe(2)
        ->and(config('database.connections.restore_check.database'))->not->toBe($this->databaseFile);
});

test('the scratch database is cleaned up afterwards', function () {
    seedWidgets(1);

    $backup = app(DatabaseBackup::class);
    $backup->verifyRestore($backup->create());

    expect(is_file((string) config('database.connections.restore_check.database')))->toBeFalse();
});

test('row count drift is reported without being called corruption', function () {
    seedWidgets(2);

    $backup = app(DatabaseBackup::class);
    $path = $backup->create();

    // Rows written after the dump are legitimate drift, not a bad backup.
    seedWidgets(1);

    $result = $backup->verifyRestore($path);

    expect($result['missing'])->toBe([])
        ->and($result['drifted'])->toContain('widgets')
        ->and($result['tables']['widgets'])->toBe(['expected' => 3, 'restored' => 2]);
});

test('a table added after the dump is reported as missing', function () {
    seedWidgets(1);

    $backup = app(DatabaseBackup::class);
    $path = $backup->create();

    Schema::create('sprockets', function ($table): void {
        $table->id();
    });

    $result = $backup->verifyRestore($path);

    expect($result['missing'])->toBe(['sprockets']);
});

test('verifying a missing backup fails loudly', function () {
    expect(fn () => app(DatabaseBackup::class)->verifyRestore('backups/not-there.sqlite'))
        ->toThrow(RuntimeException::class, 'No backup found');
});

test('a backup cannot be taken inside a transaction', function () {
    DB::beginTransaction();

    try {
        expect(fn () => app(DatabaseBackup::class)->create())
            ->toThrow(RuntimeException::class, 'inside a transaction');
    } finally {
        DB::rollBack();
    }
});

test('pruning deletes dumps past the retention window and keeps the rest', function () {
    $backup = app(DatabaseBackup::class);
    $disk = Storage::disk(config('backup.disk'));

    $stale = $backup->create(Carbon::now()->subDays(60));
    $fresh = $backup->create();

    touch($disk->path($stale), Carbon::now()->subDays(60)->getTimestamp());

    expect($backup->prune())->toBe(1);

    $disk->assertMissing($stale);
    $disk->assertExists($fresh);
});

test('pruning is skipped when retention is disabled', function () {
    config(['backup.retain_days' => 0]);

    $backup = app(DatabaseBackup::class);
    $path = $backup->create();
    touch(Storage::disk(config('backup.disk'))->path($path), Carbon::now()->subYears(5)->getTimestamp());

    expect($backup->prune())->toBe(0);
    Storage::disk(config('backup.disk'))->assertExists($path);
});

test('latest returns the most recent dump', function () {
    $backup = app(DatabaseBackup::class);
    $disk = Storage::disk(config('backup.disk'));

    $older = $backup->create(Carbon::now()->subDay());
    $newer = $backup->create();

    touch($disk->path($older), Carbon::now()->subDay()->getTimestamp());

    expect($backup->latest())->toBe($newer);
});

test('latest returns null when nothing has been backed up', function () {
    expect(app(DatabaseBackup::class)->latest())->toBeNull();
});

test('the backup command writes a dump and says it is unverified', function () {
    $this->artisan('db:backup')
        ->expectsOutputToContain('Not verified')
        ->assertSuccessful();

    expect(Storage::disk(config('backup.disk'))->files('backups'))->toHaveCount(1);
});

test('the backup command can verify in the same run', function () {
    seedWidgets(2);

    $this->artisan('db:backup --verify')
        ->expectsOutputToContain('Restored')
        ->assertSuccessful();
});

test('the verify command fails when there is nothing to verify', function () {
    $this->artisan('db:verify-restore')
        ->expectsOutputToContain('no backups to verify')
        ->assertFailed();
});

test('the verify command picks up the latest backup on its own', function () {
    seedWidgets(1);

    $this->artisan('db:backup')->assertSuccessful();

    $this->artisan('db:verify-restore')
        ->expectsOutputToContain('Restored')
        ->assertSuccessful();
});
