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
