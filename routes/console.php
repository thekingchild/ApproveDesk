<?php

use App\Jobs\ApplyOutOfOfficeFallbacksJob;
use App\Jobs\ProcessEscalation;
use App\Jobs\PurgeExpiredDelegations;
use App\Jobs\SendNotificationDigests;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared hosts need only one cron entry: `php artisan schedule:run` each
// minute. Each invocation drains the database queue and exits cleanly.
Schedule::command('queue:work --stop-when-empty --tries=3 --timeout=90')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::job(new PurgeExpiredDelegations)->daily();
Schedule::job(new SendNotificationDigests)->dailyAt('08:00');
Schedule::job(new ProcessEscalation)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new ApplyOutOfOfficeFallbacksJob)->everyFifteenMinutes()->withoutOverlapping();
