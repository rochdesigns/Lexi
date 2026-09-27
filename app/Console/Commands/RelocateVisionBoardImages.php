<?php

namespace App\Console\Commands;

use App\Models\VisionBoardItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RelocateVisionBoardImages extends Command
{
    /**
     * @var string
     */
    protected $signature = 'vision-board:relocate-images
        {--dry-run : List what would move without moving it}
        {--prune-orphans : Also delete public files that no vision board item references}';

    /**
     * @var string
     */
    protected $description = 'Move vision board images off the public disk onto the private uploads disk';

    /**
     * Copy every image still on the public disk over to the uploads disk.
     *
     * Vision board images used to be written to the public disk, where they
     * were readable by anyone who knew the URL. This moves the existing files
     * to their private home. It is safe to run repeatedly: files already
     * present on the uploads disk are left alone, and the public copy is only
     * removed once the private copy is confirmed written.
     */
    public function handle(): int
    {
        $public = Storage::disk('public');
        $uploads = VisionBoardItem::disk();
        $dryRun = (bool) $this->option('dry-run');

        if ($public->path('') === $uploads->path('')) {
            $this->components->warn('The public and uploads disks resolve to the same location; nothing to do.');

            return self::SUCCESS;
        }

        $moved = 0;
        $skipped = 0;
        $missing = 0;

        foreach (VisionBoardItem::query()->cursor() as $item) {
            $path = $item->image_path;

            if ($uploads->exists($path)) {
                $skipped++;

                continue;
            }

            if (! $public->exists($path)) {
                $this->components->warn("Missing on both disks: {$path}");
                $missing++;

                continue;
            }

            if ($dryRun) {
                $this->line("Would move: {$path}");
                $moved++;

                continue;
            }

            $stream = $public->readStream($path);

            if ($stream === null) {
                $this->components->error("Could not read: {$path}");
                $missing++;

                continue;
            }

            $written = $uploads->writeStream($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($written === false) {
                $this->components->error("Write failed, leaving the public copy in place: {$path}");
                $missing++;

                continue;
            }

            // Only now is it safe to drop the public copy.
            $public->delete($path);
            $moved++;
        }

        $this->components->info(sprintf(
            '%s %d file(s). Skipped %d already private, %d unreadable.',
            $dryRun ? 'Would move' : 'Moved',
            $moved,
            $skipped,
            $missing,
        ));

        $this->reportOrphans($dryRun);

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Report — and optionally delete — public files nothing references.
     *
     * Replacing or deleting an item used to leave its file behind on the
     * public disk, still readable by URL. Those files are not recoverable
     * through the app, so they are only removed when asked for explicitly.
     */
    private function reportOrphans(bool $dryRun): void
    {
        $public = Storage::disk('public');
        $referenced = VisionBoardItem::query()->pluck('image_path')->all();
        $orphans = array_values(array_diff($public->allFiles('vision-board'), $referenced));

        if ($orphans === []) {
            return;
        }

        $prune = (bool) $this->option('prune-orphans');

        foreach ($orphans as $orphan) {
            if ($prune && ! $dryRun) {
                $public->delete($orphan);
            }

            $this->line(($prune ? ($dryRun ? 'Would delete orphan: ' : 'Deleted orphan: ') : 'Orphan: ').$orphan);
        }

        $this->components->warn(sprintf(
            '%d publicly readable file(s) are referenced by no vision board item.%s',
            count($orphans),
            $prune ? '' : ' Re-run with --prune-orphans to delete them.',
        ));
    }
}
