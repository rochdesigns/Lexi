<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The framework's verification mail, moved off the request.
 *
 * Sent inline, whoever clicked "resend" waits on the mail provider, and a
 * provider that cannot be reached becomes their error page rather than a
 * retried background job. The signed URL is built when the job runs, so the
 * link's lifetime starts at delivery instead of at the click.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use Queueable;
}
