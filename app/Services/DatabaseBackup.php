<?php

namespace App\Services;

use App\Console\Commands\ExportData;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Takes and restores native database dumps.
 *
 * "Native" matters: a JSON row dump (see the data:export command) is a
 * migration aid, not a backup — it cannot reproduce schema, indexes or
 * constraints. This produces something the database's own tooling can
 * restore, which is the only kind of backup worth trusting.
 */
class DatabaseBackup
{
    /** Engines this class knows how to dump. */
    private const SUPPORTED_DRIVERS = ['mysql', 'mariadb', 'sqlite'];

    /**
     * Write a dump of the current database and return its path on the disk.
     */
    public function create(?Carbon $at = null): string
    {
        $driver = $this->driver();

        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'A backup cannot be taken inside a transaction: the snapshot would not be consistent, '
                .'and SQLite refuses to VACUUM from within one.'
            );
        }

        $at ??= Carbon::now();

        $path = sprintf(
            '%s/%s-%s.%s',
            rtrim((string) config('backup.path'), '/'),
            $this->databaseName(),
            $at->format('Y-m-d-His'),
            $driver === 'sqlite' ? 'sqlite' : 'sql',
        );

        $contents = $driver === 'sqlite'
            ? $this->readSqliteFile()
            : $this->runMysqlDump();

        $this->disk()->put($path, $contents);

        return $path;
    }

    /**
     * Restore a dump into a scratch database and compare it to the original.
     *
     * This is the part that is usually skipped. A backup nobody has restored
     * is a guess, so this reports the row count of every table on both sides
     * and names any that disagree.
     *
     * Row counts are advisory: a dump taken an hour ago legitimately holds
     * fewer rows than the live database. A *missing table* is not advisory —
     * it means the dump cannot rebuild the schema.
     *
     * @return array{tables: array<string, array{expected: int, restored: int}>, missing: list<string>, drifted: list<string>}
     */
    public function verifyRestore(string $path): array
    {
        $disk = $this->disk();

        if (! $disk->exists($path)) {
            throw new RuntimeException("No backup found at [{$path}] on the [".config('backup.disk').'] disk.');
        }

        $expected = $this->rowCounts('__source__');

        $connection = $this->driver() === 'sqlite'
            ? $this->restoreSqliteToScratch($path)
            : $this->restoreMysqlToScratch($path);

        try {
            $restored = $this->rowCounts($connection);
        } finally {
            $this->tearDownScratch($connection);
        }

        $tables = [];
        $missing = [];
        $drifted = [];

        foreach ($expected as $table => $count) {
            if (! array_key_exists($table, $restored)) {
                $missing[] = $table;
                $tables[$table] = ['expected' => $count, 'restored' => -1];

                continue;
            }

            $tables[$table] = ['expected' => $count, 'restored' => $restored[$table]];

            if ($restored[$table] !== $count) {
                $drifted[] = $table;
            }
        }

        return ['tables' => $tables, 'missing' => $missing, 'drifted' => $drifted];
    }

    /**
     * Delete dumps older than the configured retention window.
     *
     * @return int The number of dumps deleted.
     */
    public function prune(?Carbon $now = null): int
    {
        $retainDays = (int) config('backup.retain_days');

        if ($retainDays <= 0) {
            return 0;
        }

        $disk = $this->disk();
        $cutoff = ($now ?? Carbon::now())->subDays($retainDays)->getTimestamp();
        $deleted = 0;

        foreach ($disk->files((string) config('backup.path')) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Get the most recent dump on the disk, or null when there are none.
     */
    public function latest(): ?string
    {
        $disk = $this->disk();
        $files = $disk->files((string) config('backup.path'));

        if ($files === []) {
            return null;
        }

        usort($files, fn (string $a, string $b): int => $disk->lastModified($b) <=> $disk->lastModified($a));

        return $files[0];
    }

    /**
     * Get the driver of the default connection, failing on unsupported ones.
     */
    public function driver(): string
    {
        $driver = (string) DB::connection()->getDriverName();

        if (! in_array($driver, self::SUPPORTED_DRIVERS, true)) {
            throw new RuntimeException("Backups are not implemented for the [{$driver}] driver.");
        }

        return $driver;
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('backup.disk'));
    }

    private function databaseName(): string
    {
        $database = (string) DB::connection()->getDatabaseName();

        return $this->driver() === 'sqlite'
            ? pathinfo($database, PATHINFO_FILENAME)
            : $database;
    }

    /**
     * Snapshot a SQLite database using its own backup mechanism.
     *
     * `VACUUM INTO` is used rather than copying the file: it produces a
     * consistent snapshot even while the database is being written to, it
     * folds in any outstanding WAL pages, and it is the only approach that
     * also works for an in-memory database.
     */
    private function readSqliteFile(): string
    {
        $scratch = $this->temporaryFilePath();

        // VACUUM INTO refuses to overwrite, so the target must not exist.
        if (is_file($scratch)) {
            unlink($scratch);
        }

        DB::statement('VACUUM INTO ?', [$scratch]);

        try {
            $contents = file_get_contents($scratch);

            if ($contents === false) {
                throw new RuntimeException('Could not read the SQLite snapshot.');
            }

            return $contents;
        } finally {
            if (is_file($scratch)) {
                unlink($scratch);
            }
        }
    }

    /**
     * Get a unique path in the system temp directory for a scratch database.
     */
    private function temporaryFilePath(): string
    {
        return sprintf(
            '%s/lexi-%s.sqlite',
            rtrim(sys_get_temp_dir(), '/'),
            bin2hex(random_bytes(8)),
        );
    }

    private function runMysqlDump(): string
    {
        $config = DB::connection()->getConfig();

        $result = Process::env($this->mysqlEnvironment($config))->run([
            (string) config('backup.mysqldump_binary'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--skip-lock-tables',
            (string) $config['database'],
        ]);

        if ($result->failed()) {
            throw new RuntimeException('mysqldump failed: '.trim($result->errorOutput()));
        }

        return $result->output();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function mysqlEnvironment(array $config): array
    {
        // Passed by environment so the password never appears in the process
        // list or in any shell history.
        return ['MYSQL_PWD' => (string) ($config['password'] ?? '')];
    }

    /**
     * Restore a SQLite dump into a scratch file and register a connection.
     */
    private function restoreSqliteToScratch(string $path): string
    {
        $scratch = $this->temporaryFilePath();
        file_put_contents($scratch, (string) $this->disk()->get($path));

        config(['database.connections.restore_check' => [
            ...DB::connection()->getConfig(),
            'database' => $scratch,
        ]]);

        DB::purge('restore_check');

        return 'restore_check';
    }

    /**
     * Restore a MySQL dump into a scratch database and register a connection.
     */
    private function restoreMysqlToScratch(string $path): string
    {
        $config = DB::connection()->getConfig();
        $scratchDatabase = $this->databaseName().'_restore_check';

        DB::statement("DROP DATABASE IF EXISTS `{$scratchDatabase}`");
        DB::statement("CREATE DATABASE `{$scratchDatabase}`");

        $result = Process::env($this->mysqlEnvironment($config))
            ->input((string) $this->disk()->get($path))
            ->run([
                (string) config('backup.mysql_binary'),
                '--host='.($config['host'] ?? '127.0.0.1'),
                '--port='.($config['port'] ?? 3306),
                '--user='.($config['username'] ?? ''),
                $scratchDatabase,
            ]);

        if ($result->failed()) {
            DB::statement("DROP DATABASE IF EXISTS `{$scratchDatabase}`");

            throw new RuntimeException('Restoring the dump failed: '.trim($result->errorOutput()));
        }

        config(['database.connections.restore_check' => [
            ...(array) $config,
            'database' => $scratchDatabase,
        ]]);

        DB::purge('restore_check');

        return 'restore_check';
    }

    private function tearDownScratch(string $connection): void
    {
        $database = (string) DB::connection($connection)->getDatabaseName();

        DB::purge($connection);

        if ($this->driver() === 'sqlite') {
            if (is_file($database)) {
                unlink($database);
            }

            return;
        }

        DB::statement("DROP DATABASE IF EXISTS `{$database}`");
    }

    /**
     * Count the rows in every application table on the given connection.
     *
     * @return array<string, int>
     */
    private function rowCounts(string $connection): array
    {
        $resolved = $connection === '__source__' ? null : $connection;
        $counts = [];

        foreach (Schema::connection($resolved)->getTables() as $table) {
            $name = $table['name'];

            if (in_array($name, ExportData::SKIPPED_TABLES, true)) {
                continue;
            }

            $counts[$name] = DB::connection($resolved)->table($name)->count();
        }

        return $counts;
    }
}
