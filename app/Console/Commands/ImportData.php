<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ImportData extends Command
{
    protected $signature = 'data:import
                            {path=data-export.json : File to read, relative to the disk root}
                            {--disk=local : Filesystem disk to read from}
                            {--force : Import even if tables already contain rows}';

    protected $description = 'Import data produced by data:export into the current database';

    public function handle(): int
    {
        $disk = Storage::disk((string) $this->option('disk'));
        $path = (string) $this->argument('path');

        if (! $disk->exists($path)) {
            $this->error("Cannot find {$path} on the {$this->option('disk')} disk.");

            return self::FAILURE;
        }

        /** @var array<string, list<array<string, mixed>>> $data */
        $data = json_decode((string) $disk->get($path), true, flags: JSON_THROW_ON_ERROR);
        $data = array_filter($data, fn (array $rows, string $table): bool => Schema::hasTable($table), ARRAY_FILTER_USE_BOTH);

        $occupied = array_filter(array_keys($data), fn (string $table): bool => DB::table($table)->exists());

        if ($occupied !== [] && ! $this->option('force')) {
            $this->error('These tables already contain rows: '.implode(', ', $occupied).'. Use --force to import anyway.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($data): void {
            foreach ($this->orderByDependencies(array_keys($data)) as $table) {
                $this->importTable($table, $data[$table]);
            }
        });

        $this->info(sprintf('Imported %d rows into %d tables.', array_sum(array_map('count', $data)), count($data)));

        return self::SUCCESS;
    }

    /**
     * Order tables so that referenced tables are inserted before those referencing them.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    private function orderByDependencies(array $tables): array
    {
        $dependencies = [];

        foreach ($tables as $table) {
            $dependencies[$table] = collect(Schema::getForeignKeys($table))
                ->pluck('foreign_table')
                ->unique()
                ->reject(fn (string $foreign): bool => $foreign === $table || ! in_array($foreign, $tables, true))
                ->values()
                ->all();
        }

        $ordered = [];

        while ($dependencies !== []) {
            $ready = array_keys(array_filter($dependencies, fn (array $needs): bool => array_diff($needs, $ordered) === []));

            // A circular reference cannot be ordered; fall back to the remaining tables as-is.
            $ready = $ready === [] ? array_keys($dependencies) : $ready;

            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($dependencies[$table]);
            }
        }

        return $ordered;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function importTable(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}
