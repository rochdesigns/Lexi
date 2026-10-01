<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a human that queued work is piling up untouched.
 *
 * Deliberately not a ShouldQueue mailable. The queue is the thing that has
 * failed when this is sent, so queueing the alarm would file it behind the
 * backlog it is reporting. It goes out in the request, which the HTTP mail
 * transport manages inside a second.
 */
class QueueStalled extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $waitedSeconds,
        public readonly string $connectionName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Queued mail is not being delivered',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.queue-stalled');
    }
}
