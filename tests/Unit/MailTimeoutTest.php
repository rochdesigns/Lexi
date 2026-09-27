<?php

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

test('the smtp transport carries a connection timeout', function () {
    // Without one, PHP's default_socket_timeout of 60s applies, and a host
    // that silently drops outbound SMTP hangs the request until nginx gives
    // up. A queued notification is the real answer; this keeps a synchronous
    // send from ever holding a web request open for a minute.
    config()->set('mail.mailers.smtp.timeout', 10);

    $transport = Mail::build(config('mail.mailers.smtp'))->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(EsmtpTransport::class)
        ->and($transport->getStream()->getTimeout())->toBe(10.0);
});

test('the shipped configuration sets a timeout rather than leaving it null', function () {
    expect(config('mail.mailers.smtp.timeout'))
        ->not->toBeNull()
        ->toBeLessThanOrEqual(30);
});
