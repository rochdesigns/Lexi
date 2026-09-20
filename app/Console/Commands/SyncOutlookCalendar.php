<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class SyncOutlookCalendar extends Command
{
    protected $signature = 'calendar:sync-outlook
                            {--data= : JSON array of events from Outlook}
                            {--file= : Path to a JSON file holding the events array (preferred: avoids shell quoting issues)}
                            {--user=1 : User ID to sync events for}
                            {--from= : Start of the synced window (Y-m-d). Defaults to the earliest date in the payload}
                            {--to= : End of the synced window (Y-m-d). Defaults to the latest date in the payload}
                            {--no-prune : Leave events Outlook no longer returns in place instead of marking them cancelled}';

    protected $description = 'Sync Outlook calendar events into Lexi';

    public function handle(): int
    {
        $userId = (int) $this->option('user');
        $user = User::find($userId);

        if (! $user) {
            $this->error("User {$userId} not found.");

            return self::FAILURE;
        }

        $file = $this->option('file');
        $json = $this->option('data');

        // --file is preferred: event titles routinely contain apostrophes and
        // ampersands, which break shell quoting when passed via --data.
        if ($file !== null && $file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                $this->error("Cannot read --file: {$file}");

                return self::FAILURE;
            }

            $json = file_get_contents($file);

            if ($json === false) {
                $this->error("Failed to read --file: {$file}");

                return self::FAILURE;
            }
        }

        if ($json === null || $json === '') {
            $this->error('No events provided. Pass --file=<path.json> (preferred) or --data=<json>.');

            return self::FAILURE;
        }

        $events = json_decode($json, true);

        if (! is_array($events)) {
            $this->error('Event payload must be a valid JSON array.');

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $restored = 0;
        $cancelled = 0;
        $invalid = 0;

        /** @var array<string, true> $seen Keys ("date|title") Outlook still returns. */
        $seen = [];

        /** @var list<string> $dates Every valid date in the payload, for inferring the window. */
        $dates = [];

        foreach ($events as $event) {
            // Outlook subjects arrive with stray leading/trailing whitespace and
            // occasional double spaces. Without normalising, "Meeting " and
            // "Meeting" are distinct titles and dedupe misses, creating duplicates.
            $title = isset($event['title']) && is_string($event['title'])
                ? trim(preg_replace('/\s+/', ' ', $event['title']))
                : '';

            $rawDate = $event['date'] ?? null;
            $time = $event['time'] ?? '00:00';
            $endTime = isset($event['end_time']) && is_string($event['end_time']) && $event['end_time'] !== ''
                ? $event['end_time']
                : null;

            if ($title === '' || ! is_string($rawDate) || $rawDate === '') {
                $invalid++;

                continue;
            }

            try {
                $date = Carbon::parse($rawDate)->toDateString();
            } catch (\Throwable) {
                $this->warn("Skipping '{$title}': unparseable date '{$rawDate}'.");
                $invalid++;

                continue;
            }

            $seen[$date.'|'.$title] = true;
            $dates[] = $date;

            // withTrashed so a meeting that was cancelled and later reinstated is
            // restored in place rather than piling up a second row beside the
            // soft-deleted one.
            $existing = Event::withTrashed()
                ->where('user_id', $userId)
                ->where('title', $title)
                ->whereDate('date', $date)
                ->first();

            if ($existing !== null) {
                $wasRestored = $existing->trashed();

                if ($wasRestored) {
                    $existing->restore();
                    $restored++;
                }

                // An event Outlook still returns is proven Outlook-sourced. Marking
                // it here, rather than inferring from tag and colour, is what keeps
                // the sweep below off hand-made Work events.
                if ($existing->source === null) {
                    $existing->source = 'outlook';
                    $existing->save();
                }

                // Backfill only: events synced before end_time was captured have a
                // null end_time. Filling those in keeps re-runs useful without
                // clobbering an end time the user has since edited by hand.
                if ($endTime !== null && $existing->end_time === null) {
                    $existing->update(['end_time' => $endTime]);
                    $updated++;

                    continue;
                }

                if (! $wasRestored) {
                    $skipped++;
                }

                continue;
            }

            Event::create([
                'user_id' => $userId,
                'title' => $title,
                'date' => $date,
                'time' => $time,
                'end_time' => $endTime,
                'tag' => 'Work',
                'color' => '#3f7fb0',
                'source' => 'outlook',
            ]);

            $created++;
        }

        $window = $this->resolveWindow($dates);

        if ($window !== null && ! $this->option('no-prune')) {
            [$from, $to] = $window;

            $stale = Event::where('user_id', $userId)
                ->where('source', 'outlook')
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->get()
                ->reject(fn (Event $event): bool => isset($seen[$event->date->toDateString().'|'.$event->title]));

            foreach ($stale as $event) {
                $event->delete();
                $cancelled++;
            }
        }

        $summary = "Outlook sync complete: {$created} created, {$updated} updated, {$skipped} skipped.";

        // Only reported when non-zero, so a routine no-op sync stays a one-liner.
        if ($restored > 0) {
            $summary .= " {$restored} restored.";
        }

        if ($cancelled > 0) {
            $summary .= " {$cancelled} cancelled.";
        }

        if ($invalid > 0) {
            $summary .= " {$invalid} malformed entr".($invalid === 1 ? 'y' : 'ies').' ignored.';
        }

        $this->info($summary);

        return self::SUCCESS;
    }

    /**
     * Resolve the date range the sweep is allowed to touch.
     *
     * Inferred from the payload unless --from/--to say otherwise. An empty payload
     * only prunes when both bounds are explicit: otherwise a failed or truncated
     * Outlook fetch would read as "everything was cancelled".
     *
     * @param  list<string>  $dates
     * @return array{0: string, 1: string}|null
     */
    private function resolveWindow(array $dates): ?array
    {
        $from = $this->option('from');
        $to = $this->option('to');

        if ($from !== null && $from !== '' && $to !== null && $to !== '') {
            try {
                return [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()];
            } catch (\Throwable) {
                $this->warn('Ignoring unparseable --from/--to; falling back to the payload date range.');
            }
        }

        if ($dates === []) {
            return null;
        }

        return [min($dates), max($dates)];
    }
}
