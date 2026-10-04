<?php

use App\Mail\QueueStalled;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Push a real job onto the database queue and age it, so the row under test is
 * the one the queue itself writes rather than a hand-built fixture.
 */
function pendingJobWaitingFor(int $seconds): void
{
    dispatch(function () {
        // Never runs; the point is the row it leaves behind.
    });

    DB::table('jobs')->update([
        'available_at' => now()->subSeconds($seconds)->getTimestamp(),
        'created_at' => now()->subSeconds($seconds)->getTimestamp(),
    ]);
}

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('it fails when a job has been waiting longer than the allowed time', function () {
    pendingJobWaitingFor(seconds: 1800);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])
        ->assertFailed();
});

test('it passes when nothing is queued', function () {
    $this->artisan('queue:verify-draining', ['--max-wait' => 300])
        ->assertSuccessful();
});

test('it passes when a job was queued only moments ago', function () {
    pendingJobWaitingFor(seconds: 5);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])
        ->assertSuccessful();
});

test('it ignores a job a worker has already reserved', function () {
    // A worker sets reserved_at and leaves available_at alone, so a long job in
    // progress must not read as a queue nobody is draining.
    pendingJobWaitingFor(seconds: 1800);

    DB::table('jobs')->update(['reserved_at' => now()->getTimestamp()]);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])
        ->assertSuccessful();
});

test('it ignores a job that is not due to run yet', function () {
    dispatch(function () {
        // Deliberately delayed; waiting is what was asked for, not a stall.
    })->delay(now()->addHour());

    DB::table('jobs')->update(['created_at' => now()->subDay()->getTimestamp()]);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])
        ->assertSuccessful();
});

test('it says the check does not apply when the queue is not the database driver', function () {
    config(['queue.default' => 'sync']);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])
        ->expectsOutputToContain('sync')
        ->assertSuccessful();
});

test('it logs an error so an unattended queue leaves a trace', function () {
    Log::spy();

    pendingJobWaitingFor(seconds: 1800);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])->assertFailed();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'queue'));
});

test('it mails the alert to the configured address', function () {
    Mail::fake();
    config(['queue_monitor.alert_email' => 'ops@example.com']);

    pendingJobWaitingFor(seconds: 1800);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])->assertFailed();

    Mail::assertSent(QueueStalled::class, fn (QueueStalled $mail): bool => $mail->hasTo('ops@example.com'));
});

test('it does not queue the alert, because the queue is what is broken', function () {
    Mail::fake();
    config(['queue_monitor.alert_email' => 'ops@example.com']);

    pendingJobWaitingFor(seconds: 1800);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])->assertFailed();

    Mail::assertNotQueued(QueueStalled::class);
});

test('it sends no alert when no address is configured', function () {
    Mail::fake();
    config(['queue_monitor.alert_email' => null]);

    pendingJobWaitingFor(seconds: 1800);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])->assertFailed();

    Mail::assertNothingOutgoing();
});

test('it takes the allowance from config when the option is omitted', function () {
    // Chosen so a hardcoded default would reach the opposite verdict.
    config(['queue_monitor.max_wait' => 1200]);

    pendingJobWaitingFor(seconds: 600);

    $this->artisan('queue:verify-draining')->assertSuccessful();
});

test('it still reports the stall when the alert mail itself fails', function () {
    // The mailer being broken is plausible precisely when this fires, and a
    // failed alarm must not bury the thing it was raised about.
    config(['queue_monitor.alert_email' => 'ops@example.com']);

    Mail::shouldReceive('to')->andThrow(new RuntimeException('the mailer is down'));

    pendingJobWaitingFor(seconds: 1800);

    $this->artisan('queue:verify-draining', ['--max-wait' => 300])->assertFailed();
});

test('the alert renders, and names the wait and the queue', function () {
    // It previously could not: a $connection property on the mailable collided
    // with the one Queueable defines, which is a fatal at class composition.
    $alert = new QueueStalled(1800, 'database');

    expect($alert->render())
        ->toContain('1800')
        ->toContain('database');
});
