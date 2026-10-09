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

// Daily hub billing: anniversary recurring invoices, renew-day card collection, grace penalties.
Schedule::command('billing:process-module-cycles')
    ->dailyAt('01:15')
    ->withoutOverlapping();

// Per-hub backup schedules (time/timezone set on Central). Runs every minute and
// only creates a backup when that hub's configured local time matches.
// Requires: system cron `* * * * * php artisan schedule:run` (or schedule:work).
Schedule::command('hubs:run-due-backups')
    ->everyMinute()
    ->withoutOverlapping(120);

// UK GDPR Phase 4: prune expired activity logs, auth tokens, sessions,
// advisor-import files, and closed support tickets (config/gdpr.php).
// Does not prune compliance_audit_events or hub backups (those use 6B retention).
Schedule::command('gdpr:prune-retention')
    ->dailyAt('02:30')
    ->withoutOverlapping();
