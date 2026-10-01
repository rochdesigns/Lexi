<?php

namespace App\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Reports whether queued work is actually being consumed.
 *
 * Verification and password reset mail is queued, so a queue with nothing
 * draining it accepts those notifications and never delivers them. That failure
 * is silent from the request's side -- the sender gets a success response -- so
 * it has to be looked for deliberately.
 */
class QueueHealth
{
    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * The name of the queue connection jobs are dispatched to.
     */
    public function connectionName(): string
    {
        return (string) $this->config->get('queue.default');
    }

    /**
     * Whether pending work is visible in a database table this can inspect.
     *
     * Only the database driver keeps a queryable backlog. `sync` runs jobs in
     * the request and leaves nothing behind, and the remote drivers hold their
     * backlog somewhere this does not reach.
     */
    public function drainsThroughDatabase(): bool
    {
        return $this->config->get("queue.connections.{$this->connectionName()}.driver") === 'database';
    }

    /**
     * Seconds the longest-waiting job that a worker should already have taken
     * has been sitting on the queue, or null when nothing is waiting.
     *
     * Jobs a worker has reserved are excluded, since a slow job in progress is
     * not an unattended queue, and so are jobs whose delay has not elapsed.
     */
    public function longestWait(): ?int
    {
        if (! $this->drainsThroughDatabase()) {
            return null;
        }

        $connection = $this->config->get("queue.connections.{$this->connectionName()}.connection");
        $table = (string) ($this->config->get("queue.connections.{$this->connectionName()}.table") ?: 'jobs');

        $now = Carbon::now()->getTimestamp();

        $oldest = $this->database->connection($connection)
            ->table($table)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', $now)
            ->min('available_at');

        return $oldest === null ? null : $now - (int) $oldest;
    }
}
