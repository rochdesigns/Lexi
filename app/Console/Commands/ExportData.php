<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ExportData extends Command
{
    /**
     * Tables holding framework state rather than application data.
     *
     * @var list<string>
     */
    public const SKIPPED_TABLES = [
        'migrations',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
    ];

    protected $signature = 'data:export
                            {path=data-export.json : File to write, relative to the disk root}
                            {--disk=local : Filesystem disk to write to}';

    protected $description = 'Export all application data to a database-agnostic JSON file';

    public function handle(): int
    {
        $data = [];

        foreach (Schema::getTables() as $table) {
            if (in_array($table['name'], self::SKIPPED_TABLES, true)) {
                continue;
            }

            $data[$table['name']] = DB::table($table['name'])->get()->map(fn (object $row): array => (array) $row)->all();
        }

        $path = (string) $this->argument('path');
        $disk = Storage::disk((string) $this->option('disk'));
        $disk->put($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $this->info(sprintf('Exported %d rows from %d tables to %s.', array_sum(array_map('count', $data)), count($data), $path));
        $this->warn('The file contains personal and financial data and password hashes. Delete it once imported.');

        return self::SUCCESS;
    }
}
