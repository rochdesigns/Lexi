<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Throwable;

class VerifyDatabaseRestore extends Command
{
    /**
     * @var string
     */
    protected $signature = 'db:verify-restore {--backup= : Dump to verify; defaults to the most recent}';

    /**
     * @var string
     */
    protected $description = 'Restore a dump into a scratch database to prove the backup works';

    /**
     * Prove a backup restores, rather than assuming it.
     *
     * The dump is restored into a throwaway database beside the real one,
     * which is dropped again afterwards. The live database is never written
     * to.
     */
    public function handle(DatabaseBackup $backup): int
    {
        $path = (string) ($this->option('backup') ?: $backup->latest());

        if ($path === '') {
            $this->components->error('There are no backups to verify. Run `db:backup` first.');

            return self::FAILURE;
        }

        $this->components->info("Restoring {$path} into a scratch database.");

        try {
            $result = $backup->verifyRestore($path);
        } catch (Throwable $e) {
            $this->components->error('The backup did not restore: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['missing'] !== []) {
            $this->components->error(
                'Tables missing after restore: '.implode(', ', $result['missing'])
                .'. This dump cannot rebuild the database.'
            );

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('<fg=gray>table</>', '<fg=gray>live / restored</>');

        foreach ($result['tables'] as $table => $counts) {
            $this->components->twoColumnDetail(
                $table,
                sprintf('%d / %d', $counts['expected'], $counts['restored']),
            );
        }

        if ($result['drifted'] !== []) {
            $this->components->warn(
                'Row counts differ for: '.implode(', ', $result['drifted'])
                .'. Expected if the database changed after the dump was taken.'
            );
        }

        $this->components->info(sprintf(
            'Restored %d table(s) successfully.',
            count($result['tables']),
        ));

        return self::SUCCESS;
    }
}
