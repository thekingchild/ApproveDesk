<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupSnapshotKind;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backups\BackupExecutionGuard;
use App\Services\Backups\ScheduledBackupHealth;
use App\Support\BackupSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BackupScheduleUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_backup_page_uses_pickable_times_and_preserves_saved_clocks(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('admin.backups.schedule')
            ->assertSeeHtml('type="time"')
            ->assertSee('Times use')
            ->set('databaseHour', '00:00')
            ->set('filesHour', '23:59')
            ->call('saveSettings')
            ->assertHasNoErrors();

        $this->assertSame('00:00', Setting::get('backup.database_hour'));
        $this->assertSame('23:59', Setting::get('backup.files_hour'));
        $this->assertSame('00:00', BackupSettings::databaseAt());
        $this->assertSame('23:59', BackupSettings::filesAt());
    }

    public function test_backup_page_labels_use_the_configured_timezone(): void
    {
        config(['app.timezone' => 'Africa/Lagos']);
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('admin.backups.schedule')
            ->assertSee('Africa/Lagos');
    }

    public function test_invalid_backup_time_is_rejected(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('admin.backups.schedule')
            ->set('databaseHour', '24:00')
            ->call('saveSettings')
            ->assertHasErrors(['databaseHour']);
    }

    public function test_scheduled_commands_record_evidence_but_manual_action_does_not(): void
    {
        $health = app(ScheduledBackupHealth::class);
        $this->assertNull($health->lastScheduledAt(BackupSnapshotKind::Database));

        $this->artisan('backups:database')->assertSuccessful();

        $this->assertNotNull($health->lastScheduledAt(BackupSnapshotKind::Database));
        $this->assertNotNull($health->lastScheduledSnapshot(BackupSnapshotKind::Database));
        $this->assertNotNull(Setting::get('backup.last_scheduled_database_snapshot_id'));
    }

    public function test_database_and_files_backups_share_one_execution_lock(): void
    {
        $lock = Cache::lock(BackupExecutionGuard::LOCK_NAME, 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('backups:database')
                ->expectsOutputToContain('Another backup is already running')
                ->assertFailed();
        } finally {
            $lock->release();
        }
    }
}
