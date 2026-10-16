<?php

namespace Tests\Feature\Backups;

use App\Actions\Backups\CreateDatabaseBackupAction;
use App\Actions\Backups\PromoteDatabaseBackupAction;
use App\Actions\Backups\VerifyBackupSnapshotAction;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupRestoreEvent;
use App\Models\BackupSnapshot;
use App\Models\User;
use App\Services\Backups\MysqlDumpLoader;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * RV-DATA-03: a MySQL/MariaDB promote takes a verified safety backup first,
 * rolls back to it on any failure, and never serves a half-loaded database.
 */
class PromoteSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_restore_history_records_the_safety_backup_and_discarded_actions(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $snapshot = app(CreateDatabaseBackupAction::class)->execute();

        app(PromoteDatabaseBackupAction::class)->rememberPromoted($snapshot, $admin, [
            'discarded_workflow_actions' => 3,
            'safety_snapshot_id' => 42,
        ]);

        $event = BackupRestoreEvent::query()->latest('id')->firstOrFail();
        $this->assertSame(3, $event->discarded_workflow_actions);
        $this->assertSame(42, $event->safety_snapshot_id);
    }

    public function test_failed_mysql_load_rolls_back_to_the_live_data_and_comes_back_up(): void
    {
        $this->requireMysql();
        $admin = User::factory()->create()->assignRole('admin');
        $snapshot = $this->verifiedSnapshot();
        $liveOnly = User::factory()->create(['email' => 'after-snapshot@example.com']);

        // The first load (the snapshot) dies half way; the rollback load is real.
        $this->app->instance(MysqlDumpLoader::class, new class extends MysqlDumpLoader
        {
            private int $calls = 0;

            public function replaceFromFile(string $path): void
            {
                if (++$this->calls === 1) {
                    $half = $path.'.half';
                    file_put_contents($half, substr((string) file_get_contents($path), 0, intdiv((int) filesize($path), 2)));

                    try {
                        // Drop everything and load only part of the dump, like a dropped connection.
                        parent::replaceFromFile($half);
                    } catch (Throwable) {
                        // A truncated statement may fail on its own; either way the load is incomplete.
                    } finally {
                        unlink($half);
                    }

                    throw new RuntimeException('Simulated failure while loading the snapshot.');
                }

                parent::replaceFromFile($path);
            }
        });

        try {
            try {
                app(PromoteDatabaseBackupAction::class)->execute($snapshot, $admin, (string) $snapshot->id);
                $this->fail('A failed promote reported success.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Simulated failure', $exception->getMessage());
            }

            $this->assertFalse(app()->isDownForMaintenance(), 'The site must come back up after a successful rollback.');
            $this->assertNotNull(User::query()->where('email', 'after-snapshot@example.com')->first(), 'Live data must be restored.');
            $this->assertTrue(
                BackupSnapshot::query()->where('id', '>', $snapshot->id)->exists(),
                'The safety backup stays listed.',
            );
        } finally {
            $this->resetDatabase();
        }
    }

    public function test_failed_rollback_keeps_the_site_in_maintenance_mode(): void
    {
        $this->requireMysql();
        $admin = User::factory()->create()->assignRole('admin');
        $snapshot = $this->verifiedSnapshot();

        $this->app->instance(MysqlDumpLoader::class, new class extends MysqlDumpLoader
        {
            public function replaceFromFile(string $path): void
            {
                throw new RuntimeException('Simulated load failure.');
            }
        });

        try {
            try {
                app(PromoteDatabaseBackupAction::class)->execute($snapshot, $admin, (string) $snapshot->id);
                $this->fail('A failed promote reported success.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('stays in maintenance mode', $exception->getMessage());
            }

            $this->assertTrue(app()->isDownForMaintenance());
        } finally {
            Artisan::call('up');
            $this->resetDatabase();
        }
    }

    public function test_successful_mysql_promote_keeps_the_safety_backup_listed(): void
    {
        $this->requireMysql();
        $admin = User::factory()->create()->assignRole('admin');
        $snapshot = $this->verifiedSnapshot();

        try {
            app(PromoteDatabaseBackupAction::class)->execute($snapshot, $admin, (string) $snapshot->id);

            $event = BackupRestoreEvent::query()->latest('id')->firstOrFail();
            $this->assertNotNull($event->safety_snapshot_id);
            // The safety backup is verified before the live tables are replaced.
            $this->assertSame(BackupSnapshotStatus::Verified, BackupSnapshot::query()->find($event->safety_snapshot_id)?->status);
            $this->assertFalse(app()->isDownForMaintenance());
        } finally {
            $this->resetDatabase();
        }
    }

    private function verifiedSnapshot(): BackupSnapshot
    {
        $snapshot = app(CreateDatabaseBackupAction::class)->execute();
        app(VerifyBackupSnapshotAction::class)->execute($snapshot);

        return $snapshot->fresh() ?? $snapshot;
    }

    private function requireMysql(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MySQL or MariaDB.');
        }
    }

    /** Promote runs DDL that commits, so rebuild the test schema afterwards. */
    private function resetDatabase(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = true;
        $this->seed(RolesAndPermissionsSeeder::class);
    }
}
