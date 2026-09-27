<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The framework's password reset mail, moved off the request.
 *
 * Same reasoning as [QueuedVerifyEmail]: a reset is the one flow a locked-out
 * person cannot route around, so it must not fail just because the provider
 * was slow for the seconds that request was open.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use Queueable;
}
