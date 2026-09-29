<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Catch-up for WC scheduled publishes (also uses delayed queue jobs on approve).
// Requires: `php artisan schedule:work` or system cron `* * * * * php artisan schedule:run`
// AND a queue worker for on-time delayed jobs.
Schedule::command('wc:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping();
