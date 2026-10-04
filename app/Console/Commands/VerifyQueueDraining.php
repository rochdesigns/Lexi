<?php

namespace App\Console\Commands;

use App\Mail\QueueStalled;
use App\Services\QueueHealth;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class VerifyQueueDraining extends Command
{
    /**
     * @var string
     */
    protected $signature = 'queue:verify-draining {--max-wait= : Seconds a job may wait before the queue counts as stalled}';

    /**
     * @var string
     */
    protected $description = 'Prove queued jobs are being consumed, rather than assuming a worker is running';

    /**
     * Look for queued work that nothing is picking up.
     *
     * A queue with no worker behind it does not error. It accepts the job and
     * reports success, so the only way this surfaces is by going and looking.
     */
    public function handle(QueueHealth $health, Repository $config): int
    {
        if (! $health->drainsThroughDatabase()) {
            $this->components->info(
                "The [{$health->connectionName()}] queue keeps no table of pending jobs, so there is nothing here to inspect."
            );

            return self::SUCCESS;
        }

        $maxWait = (int) ($this->option('max-wait') ?? $config->get('queue_monitor.max_wait'));

        $wait = $health->longestWait();

        if ($wait === null) {
            $this->components->info('Nothing is waiting on the queue.');

            return self::SUCCESS;
        }

        if ($wait <= $maxWait) {
            $this->components->info("The queue is draining; the longest wait is {$wait}s.");

            return self::SUCCESS;
        }

        $report = sprintf(
            'A job has been waiting %ds on the [%s] queue, past the %ds allowed. Nothing appears to be draining it, so queued mail is being accepted and not delivered.',
            $wait,
            $health->connectionName(),
            $maxWait,
        );

        $this->components->error($report);
        Log::error($report);

        $this->mailTheAlert($config->get('queue_monitor.alert_email'), $wait, $health->connectionName());

        return self::FAILURE;
    }

    /**
     * Mail the alert, if there is somewhere to mail it.
     *
     * Sent rather than queued, and best effort: the log above is the channel
     * that does not depend on the mailer working.
     */
    private function mailTheAlert(?string $address, int $wait, string $connection): void
    {
        if (blank($address)) {
            return;
        }

        try {
            Mail::to($address)->send(new QueueStalled($wait, $connection));
        } catch (Throwable $e) {
            $this->components->warn('The alert could not be mailed: '.$e->getMessage());
        }
    }
}
