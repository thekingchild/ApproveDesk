<?php

namespace Tests\Feature\Operations;

use App\Enums\BackupSnapshotKind;
use App\Models\Setting;
use App\Services\Backups\ScheduledBackupHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SchedulerCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_check_reports_fresh_heartbeat_without_mutating_it(): void
    {
        $heartbeat = now()->subMinute()->toIso8601String();
        Setting::put('ops.scheduler_heartbeat_at', $heartbeat);

        $this->artisan('ops:scheduler-check')
            ->expectsOutputToContain('Scheduler heartbeat: healthy')
            ->assertSuccessful();

        $this->assertSame($heartbeat, Setting::get('ops.scheduler_heartbeat_at'));
    }

    public function test_scheduler_check_fails_for_missing_or_stale_heartbeat(): void
    {
        $this->artisan('ops:scheduler-check')
            ->expectsOutputToContain('Scheduler heartbeat: missing')
            ->assertFailed();

        Setting::put('ops.scheduler_heartbeat_at', now()->subMinutes(6)->toIso8601String());

        $this->artisan('ops:scheduler-check')
            ->expectsOutputToContain('Scheduler heartbeat: stale')
            ->assertFailed();
    }

    public function test_next_backup_run_uses_application_timezone_and_rolls_to_tomorrow(): void
    {
        config(['app.timezone' => 'Africa/Lagos']);
        Carbon::setTestNow(Carbon::parse('2026-08-26 15:00:00', 'Africa/Lagos'));

        $health = app(ScheduledBackupHealth::class);

        $this->assertSame('2026-08-26 15:51', $health->nextRun('15:51')->format('Y-m-d H:i'));
        $this->assertSame('2026-08-27 02:45', $health->nextRun('02:45')->format('Y-m-d H:i'));

        Carbon::setTestNow();
    }

    public function test_manual_backup_does_not_create_scheduled_evidence(): void
    {
        $this->assertNull(app(ScheduledBackupHealth::class)->lastScheduledAt(BackupSnapshotKind::Database));
        $this->assertNull(app(ScheduledBackupHealth::class)->lastScheduledSnapshot(BackupSnapshotKind::Database));
    }
}
