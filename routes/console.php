<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Backups
|--------------------------------------------------------------------------
|
| A nightly dump, and a weekly drill that proves one of those dumps actually
| restores. The drill is the point: an unverified backup is a guess. Both
| need a scheduler running on the host (`php artisan schedule:work`, or a
| cron entry calling `schedule:run` every minute).
|
*/

Schedule::command('db:backup')
    ->dailyAt('03:00')
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('db:verify-restore')
    ->weeklyOn(1, '04:00')
    ->onOneServer()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Queue Health
|--------------------------------------------------------------------------
|
| Verification and password reset mail is queued, and a queue with no worker
| behind it does not fail -- it accepts the job, answers the request with a
| success, and delivers nothing. Nobody finds out until someone cannot log in.
|
| So look for it: if a job has sat unclaimed past `queue_monitor.max_wait`, log
| it and mail whoever `queue_monitor.alert_email` names. Like the backups, this
| needs a scheduler on the host to run at all.
|
*/

Schedule::command('queue:verify-draining')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();
