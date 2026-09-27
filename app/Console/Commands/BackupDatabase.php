<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Throwable;

class BackupDatabase extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:backup {--verify : Restore the dump into a scratch database and check it}';

    /**
     * @var string
     */
    protected $description = 'Write a native dump of the database and prune old ones';

    public function handle(DatabaseBackup $backup): int
    {
        try {
            $path = $backup->create();
        } catch (Throwable $e) {
            $this->components->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Wrote {$path} to the [".config('backup.disk').'] disk.');

        $pruned = $backup->prune();

        if ($pruned > 0) {
            $this->components->info("Pruned {$pruned} dump(s) older than ".config('backup.retain_days').' days.');
        }

        if (! $this->option('verify')) {
            $this->components->warn('Not verified. Run with --verify, or `db:verify-restore`, to prove it restores.');

            return self::SUCCESS;
        }

        return $this->call('db:verify-restore', ['--backup' => $path]);
    }
}
