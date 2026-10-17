<?php

namespace Tests\Feature\Backups;

use App\Actions\Backups\CreateDatabaseBackupAction;
use App\Actions\Backups\CreateFilesBackupAction;
use App\Actions\Backups\PromoteDatabaseBackupAction;
use App\Actions\Backups\PromoteFilesBackupAction;
use App\Actions\Backups\VerifyBackupSnapshotAction;
use App\Models\BackupRestoreEvent;
use App\Models\BackupSnapshot;
use App\Models\User;
use App\Services\Backups\BackupExecutionGuard;
use App\Services\Backups\BackupRestoreTrail;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Promote on a real, file-backed SQLite database (the suite's in-memory
 * database cannot be promoted).
 *
 * RV-DATA-10: the backup catalogue and restore history describe backups that
 * exist outside the database, so promoting an older snapshot must not erase the
 * records made after it.
 * RV-OPS-06: promote takes the same lock as a running backup.
 */
class PromoteKeepsBackupHistoryTest extends TestCase
{
    private string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->databaseFile = tempnam(sys_get_temp_dir(), 'ad-promote-').'.sqlite';
        touch($this->databaseFile);
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $this->databaseFile]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
        $this->seed(RolesAndPermissionsSeeder::class);

        Storage::fake('local');
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        foreach ([$this->databaseFile, $this->databaseFile.'.pre-promote'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (app()->isDownForMaintenance()) {
            Artisan::call('up');
        }

        parent::tearDown();
    }

    public function test_rv_data_10_promote_keeps_later_backups_and_restore_history(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $older = $this->verified(app(CreateDatabaseBackupAction::class)->execute());

        $later = app(CreateDatabaseBackupAction::class)->execute();
        $files = app(CreateFilesBackupAction::class)->execute();
        app(BackupRestoreTrail::class)->record($later, $admin);
        $earlierRestore = BackupRestoreEvent::query()->latest('id')->firstOrFail();

        app(PromoteDatabaseBackupAction::class)->execute($older, $admin, (string) $older->id);

        $this->assertNotNull(BackupSnapshot::query()->find($later->id), 'A backup taken after the snapshot is still listed.');
        $this->assertNotNull(BackupSnapshot::query()->find($files->id), 'A files backup taken after the snapshot is still listed.');
        $this->assertNotNull(BackupRestoreEvent::query()->find($earlierRestore->id), 'Earlier restore history survives.');
        $this->assertTrue(
            BackupRestoreEvent::query()->where('snapshot_id', $older->id)->exists(),
            'This promote is recorded too.',
        );
    }

    public function test_rv_ops_06_database_promote_waits_for_a_running_backup(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $snapshot = $this->verified(app(CreateDatabaseBackupAction::class)->execute());
        $lock = Cache::lock(BackupExecutionGuard::LOCK_NAME, 60);
        $this->assertTrue($lock->get());

        try {
            app(PromoteDatabaseBackupAction::class)->execute($snapshot, $admin, (string) $snapshot->id);
            $this->fail('Promote must not run while a backup holds the lock.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Another backup is already running', $exception->getMessage());
        } finally {
            $lock->release();
        }

        $this->assertFalse(app()->isDownForMaintenance());
    }

    public function test_rv_ops_06_files_promote_waits_for_a_running_backup(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Storage::disk('local')->put('submissions/1/a.txt', 'a');
        $snapshot = $this->verified(app(CreateFilesBackupAction::class)->execute());
        $lock = Cache::lock(BackupExecutionGuard::LOCK_NAME, 60);
        $this->assertTrue($lock->get());

        try {
            app(PromoteFilesBackupAction::class)->execute($snapshot, $admin, (string) $snapshot->id);
            $this->fail('Promote must not run while a backup holds the lock.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Another backup is already running', $exception->getMessage());
        } finally {
            $lock->release();
        }
    }

    private function verified(BackupSnapshot $snapshot): BackupSnapshot
    {
        app(VerifyBackupSnapshotAction::class)->execute($snapshot);

        return $snapshot->fresh() ?? $snapshot;
    }
}
