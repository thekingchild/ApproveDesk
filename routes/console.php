<?php

use App\Jobs\ApplyOutOfOfficeFallbacksJob;
use App\Jobs\ApplyRetentionJob;
use App\Jobs\ProcessEscalation;
use App\Jobs\RetryNotificationDeliveries;
use App\Jobs\SendNotificationDigests;
use App\Models\ApiIdempotencyKey;
use App\Models\InAppNotification;
use App\Models\NotificationDelivery;
use App\Models\OpsSnapshot;
use App\Support\BackupSettings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Shared hosts need only one cron entry: `php artisan schedule:run` each
// minute. Each invocation drains the database queue and exits cleanly. The
// worker runs in the background so the rest of the schedule stays on time,
// stops after 50 s so ticks do not stack up, and its overlap lock expires
// after 5 minutes so a killed worker cannot stop mail for a day (RV-OPS-01).
// Keep queue.connections.database.retry_after above --timeout.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3 --timeout=90')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('ops:heartbeat')->everyMinute()->withoutOverlapping();

Schedule::job(new SendNotificationDigests)->dailyAt('08:00');
Schedule::job(new ProcessEscalation)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new ApplyOutOfOfficeFallbacksJob)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new RetryNotificationDeliveries)->everyFifteenMinutes()->withoutOverlapping();
Schedule::job(new ApplyRetentionJob)->dailyAt('04:15')->withoutOverlapping();
Schedule::command('approvedesk:fetch-whatsapp-templates')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('users:prune-unconfirmed')->dailyAt('03:45');
// Housekeeping (RV-OPS-05). Kept: unread notifications, deliveries about requests
// under legal hold, evidence-pack records, and the activity log (audit evidence).
Schedule::command('model:prune', ['--model' => [OpsSnapshot::class, NotificationDelivery::class, InAppNotification::class, ApiIdempotencyKey::class]])->dailyAt('03:30')->withoutOverlapping();
Schedule::command('queue:prune-failed', ['--hours' => 24 * 30])->dailyAt('03:35')->withoutOverlapping();
Schedule::command('evidence:prune-files')->dailyAt('03:40')->withoutOverlapping();
Schedule::command('cache:prune-file-cache')->dailyAt('03:50')->withoutOverlapping();

Schedule::command('backups:database')
    ->dailyAt(BackupSettings::databaseAt())
    ->withoutOverlapping(120);

Schedule::command('backups:files')
    ->dailyAt(BackupSettings::filesAt())
    ->withoutOverlapping(120);

Schedule::command('backups:retain')->dailyAt('04:15')->withoutOverlapping();
