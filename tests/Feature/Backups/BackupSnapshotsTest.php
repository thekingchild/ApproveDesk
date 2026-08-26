<?php

namespace Tests\Feature\Backups;

use App\Actions\Backups\CreateDatabaseBackupAction;
use App\Actions\Backups\CreateFilesBackupAction;
use App\Actions\Backups\DeleteLocalBackupCopyAction;
use App\Actions\Backups\PromoteDatabaseBackupAction;
use App\Actions\Backups\RetainBackupSnapshotsAction;
use App\Actions\Backups\VerifyBackupSnapshotAction;
use App\Enums\BackupDestinationKind;
use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupDestination;
use App\Models\BackupSnapshot;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Services\Backups\BackupTransport;
use App\Services\Backups\BackupTransportFactory;
use App\Services\Operations\BackupVerifier;
use App\Services\Operations\RestoreDrill;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BackupSnapshotsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_database_backup_gzip_passes_backup_verifier(): void
    {
        $snapshot = app(CreateDatabaseBackupAction::class)->execute();
        $absolute = Storage::disk('local')->path((string) $snapshot->path);
        $decoded = sys_get_temp_dir().'/ad-db-'.uniqid('', true).'.sql';
        file_put_contents($decoded, (string) gzdecode((string) file_get_contents($absolute)));

        $result = app(BackupVerifier::class)->verifyPath($decoded);
        $this->assertTrue($result->ok, implode(' ', $result->issues));
        $this->assertSame(WorkflowAction::query()->count(), $snapshot->manifest['workflow_actions']);
        $this->assertSame(1, $snapshot->manifest['tables']['backup_destinations']);
        unlink($decoded);
    }

    public function test_files_backup_includes_submission_file_and_excludes_backups(): void
    {
        Storage::disk('local')->put('submissions/9/note.txt', 'invoice body');
        Storage::disk('local')->put('backups/database/should-not-appear.sql.gz', 'nope');

        $snapshot = app(CreateFilesBackupAction::class)->execute();
        $paths = array_column($snapshot->manifest['files'], 'path');

        $this->assertContains('submissions/9/note.txt', $paths);
        $this->assertFalse(collect($paths)->contains(fn (string $path): bool => str_starts_with($path, 'backups/')));
    }

    public function test_unconfigured_remote_is_skipped_and_failed_remote_keeps_local_snapshot(): void
    {
        BackupDestination::factory()->s3()->create(['enabled' => true]);
        $local = app(CreateDatabaseBackupAction::class)->execute();
        $this->assertTrue($local->isSuccessful());
        $this->assertSame(1, BackupSnapshot::query()->successful()->count());

        $remote = BackupDestination::factory()->s3()->create(['enabled' => true, 'name' => 'Broken']);
        $remote->putCredentials(['key' => 'id', 'secret' => 'secret', 'bucket' => 'bucket']);

        $this->app->bind(BackupTransportFactory::class, fn () => new class extends BackupTransportFactory
        {
            public function make(BackupDestination $destination): BackupTransport
            {
                if ($destination->kind === BackupDestinationKind::S3) {
                    return new class implements BackupTransport
                    {
                        public function put(string $name, string $absolutePath): string
                        {
                            throw new \RuntimeException('S3 exploded');
                        }

                        public function has(string $remoteId): bool
                        {
                            return false;
                        }

                        public function fetch(string $remoteId, string $destination): void {}

                        public function delete(string $remoteId): void {}

                        public function test(): bool
                        {
                            return false;
                        }
                    };
                }

                return parent::make($destination);
            }
        });

        $again = app(CreateDatabaseBackupAction::class)->execute();
        $this->assertTrue($again->isSuccessful());
        $this->assertTrue(
            BackupSnapshot::query()->where('destination_id', $remote->id)->where('status', BackupSnapshotStatus::Failed)->exists(),
        );
        $this->assertTrue(Storage::disk('local')->exists((string) $again->path));
    }

    public function test_retention_keeps_the_last_success(): void
    {
        $destination = BackupDestination::factory()->create();
        $old = BackupSnapshot::factory()->create([
            'destination_id' => $destination->id,
            'retained_until' => now()->subDay(),
            'status' => BackupSnapshotStatus::Success,
        ]);
        $kept = BackupSnapshot::factory()->create([
            'destination_id' => $destination->id,
            'retained_until' => now()->subDay(),
            'status' => BackupSnapshotStatus::Success,
        ]);

        app(RetainBackupSnapshotsAction::class)->execute();

        $this->assertNull($old->fresh());
        $this->assertNotNull($kept->fresh());
    }

    public function test_promote_without_verify_is_refused_and_tampered_dump_is_refused(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $snapshot = app(CreateDatabaseBackupAction::class)->execute();

        $this->expectException(\InvalidArgumentException::class);
        app(PromoteDatabaseBackupAction::class)->execute($snapshot, $admin, (string) $snapshot->id);
    }

    public function test_promoted_snapshot_and_its_destination_are_reinserted_when_the_dump_does_not_contain_them(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $snapshot = app(CreateDatabaseBackupAction::class)->execute();
        app(VerifyBackupSnapshotAction::class)->execute($snapshot);
        $id = $snapshot->id;
        $destinationId = $snapshot->destination_id;
        $snapshot->load('destination');

        BackupSnapshot::query()->whereKey($id)->delete();
        BackupDestination::query()->whereKey($destinationId)->delete();

        $remembered = app(PromoteDatabaseBackupAction::class)->rememberPromoted($snapshot, $admin);

        $this->assertInstanceOf(BackupSnapshot::class, $remembered);
        $this->assertSame($id, $remembered->id);
        $this->assertDatabaseHas('backup_destinations', [
            'id' => $destinationId,
            'name' => 'Local disk',
        ]);
        $this->assertSame(BackupSnapshotStatus::Promoted, BackupSnapshot::query()->find($id)?->status);
    }

    public function test_mysql_promote_restores_the_verified_snapshot(): void
    {
        if (! in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Requires MySQL.');
        }

        $admin = User::factory()->create()->assignRole('admin');
        $snapshot = app(CreateDatabaseBackupAction::class)->execute();
        app(VerifyBackupSnapshotAction::class)->execute($snapshot);
        $extra = User::factory()->create();

        try {
            app(PromoteDatabaseBackupAction::class)->execute($snapshot, $admin, (string) $snapshot->id);

            $this->assertNull($extra->fresh());
            $this->assertSame(BackupSnapshotStatus::Promoted, BackupSnapshot::query()->find($snapshot->id)?->status);
        } finally {
            $this->artisan('migrate:fresh', ['--force' => true]);
            RefreshDatabaseState::$migrated = true;
            $this->seed(RolesAndPermissionsSeeder::class);
        }
    }

    public function test_tampered_snapshot_fails_verify(): void
    {
        $snapshot = app(CreateDatabaseBackupAction::class)->execute();
        Storage::disk('local')->put((string) $snapshot->path, 'not-a-gzip');

        $this->expectException(\InvalidArgumentException::class);
        app(VerifyBackupSnapshotAction::class)->execute($snapshot);
    }

    public function test_restore_drill_does_not_change_workflow_actions_before_promote(): void
    {
        $before = WorkflowAction::query()->count();
        $snapshot = app(CreateDatabaseBackupAction::class)->execute();
        $absolute = Storage::disk('local')->path((string) $snapshot->path);
        $decoded = sys_get_temp_dir().'/ad-drill-'.uniqid('', true).'.sql';
        file_put_contents($decoded, (string) gzdecode((string) file_get_contents($absolute)));

        app(RestoreDrill::class)->run(null, $decoded);

        $this->assertSame($before, WorkflowAction::query()->count());
        unlink($decoded);
    }

    public function test_submitter_cannot_open_backups(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)->get(route('admin.backups.index'))->assertForbidden();
    }

    public function test_administrator_can_open_backups_and_create_snapshots(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee('Backup database')
            ->assertSee('S3-compatible')
            ->assertSee('Google Drive')
            ->assertSee('Save S3 destination')
            ->assertSee('Save Drive destination')
            ->assertDontSee('Add a remote destination');

        Livewire::actingAs($admin)
            ->test('pages::admin.backups')
            ->call('backupDatabase')
            ->assertHasNoErrors();

        $this->assertTrue(BackupSnapshot::query()->where('kind', BackupSnapshotKind::Database)->exists());
    }

    public function test_snapshots_are_named_and_listed_by_taken_time_newest_first(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $destination = BackupDestination::factory()->create();
        $older = BackupSnapshot::factory()->create([
            'destination_id' => $destination->id,
            'taken_at' => now()->subHour(),
            'path' => 'backups/database/2026-08-26-140000.sql.gz',
        ]);
        $newer = BackupSnapshot::factory()->create([
            'destination_id' => $destination->id,
            'taken_at' => now(),
            'path' => 'backups/database/2026-08-26-150000.sql.gz',
        ]);

        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee(__('Date and time'))
            ->assertSee($newer->displayName())
            ->assertSeeInOrder([$newer->displayName(), $older->displayName()]);

        $snapshot = app(CreateDatabaseBackupAction::class)->execute();
        $this->assertNotNull($snapshot->taken_at);
        $this->assertStringContainsString($snapshot->takenAt()->timezone((string) config('app.timezone'))->format('Y-m-d-His'), (string) $snapshot->path);
        $this->assertSame($snapshot->kind->value.'-'.$snapshot->takenAt()->timezone((string) config('app.timezone'))->format('Y-m-d-His').'.gz', $snapshot->downloadName());
    }

    public function test_promoting_a_snapshot_records_visible_restore_history(): void
    {
        $admin = User::factory()->create(['name' => 'Ada Restore'])->assignRole('admin');
        $snapshot = app(CreateDatabaseBackupAction::class)->execute();
        app(VerifyBackupSnapshotAction::class)->execute($snapshot);

        app(PromoteDatabaseBackupAction::class)->rememberPromoted($snapshot, $admin);

        $this->assertDatabaseHas('backup_restore_events', [
            'snapshot_id' => $snapshot->id,
            'actor_name' => 'Ada Restore',
            'kind' => 'database',
            'snapshot_name' => $snapshot->fresh()->displayName(),
        ]);

        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee(__('Restore history'))
            ->assertSee('Ada Restore')
            ->assertSee($snapshot->fresh()->displayName());
    }

    public function test_schedule_registers_backup_jobs_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events());
        $database = $events->first(fn ($event): bool => str_contains((string) $event->command, 'backups:database'));
        $files = $events->first(fn ($event): bool => str_contains((string) $event->command, 'backups:files'));

        $this->assertNotNull($database);
        $this->assertNotNull($files);
        $this->assertTrue($database->withoutOverlapping);
        $this->assertTrue($files->withoutOverlapping);
    }

    public function test_drive_test_connection_is_faked(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'token'], 200),
            'https://www.googleapis.com/drive/v3/files*' => Http::response(['files' => []], 200),
        ]);

        $destination = BackupDestination::factory()->drive()->create();
        $destination->putCredentials([
            'folder_id' => 'folder',
            'refresh_token' => 'refresh',
            'client_id' => 'id',
            'client_secret' => 'secret',
        ]);

        $this->assertTrue(app(BackupTransportFactory::class)->make($destination)->test());
    }

    public function test_local_copy_can_be_removed_when_a_remote_replica_exists(): void
    {
        $store = [];
        $this->bindArrayRemote($store);

        $remote = BackupDestination::factory()->s3()->create(['enabled' => true, 'name' => 'Offsite']);
        $remote->putCredentials(['key' => 'id', 'secret' => 'secret', 'bucket' => 'bucket']);

        $local = app(CreateDatabaseBackupAction::class)->execute();
        $path = (string) $local->path;
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertTrue(BackupSnapshot::query()->where('destination_id', $remote->id)->successful()->exists());

        app(DeleteLocalBackupCopyAction::class)->execute($local);

        $this->assertNull($local->fresh());
        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertTrue(BackupSnapshot::query()->where('destination_id', $remote->id)->successful()->exists());
    }

    public function test_local_copy_cannot_be_removed_without_a_remote_replica(): void
    {
        $local = app(CreateDatabaseBackupAction::class)->execute();

        $this->expectException(\InvalidArgumentException::class);
        app(DeleteLocalBackupCopyAction::class)->execute($local);
        $this->assertTrue(Storage::disk('local')->exists((string) $local->path));
    }

    public function test_verify_fetches_a_remote_snapshot_after_local_copy_is_removed(): void
    {
        $store = [];
        $this->bindArrayRemote($store);

        $remote = BackupDestination::factory()->s3()->create(['enabled' => true, 'name' => 'Offsite']);
        $remote->putCredentials(['key' => 'id', 'secret' => 'secret', 'bucket' => 'bucket']);

        $local = app(CreateDatabaseBackupAction::class)->execute();
        $replica = BackupSnapshot::query()->where('destination_id', $remote->id)->successful()->first();
        $this->assertNotNull($replica);

        app(DeleteLocalBackupCopyAction::class)->execute($local);

        $verified = app(VerifyBackupSnapshotAction::class)->execute($replica);
        $this->assertTrue($verified->isVerified());
    }

    public function test_backups_page_shows_database_and_file_sizes(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $destination = BackupDestination::factory()->create();
        BackupSnapshot::factory()->create([
            'destination_id' => $destination->id,
            'kind' => BackupSnapshotKind::Database,
            'path' => 'backups/database/size-db.sql.gz',
            'bytes' => 2048,
        ]);
        BackupSnapshot::factory()->create([
            'destination_id' => $destination->id,
            'kind' => BackupSnapshotKind::Files,
            'path' => 'backups/files/size-files.zip',
            'bytes' => 4096,
        ]);

        $this->actingAs($admin)->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee('Size')
            ->assertSee(__('Database'))
            ->assertSee(BackupSnapshot::formatBytes(2048))
            ->assertSee(BackupSnapshot::formatBytes(4096));
    }

    /**
     * @param  array<string, string>  $store
     */
    private function bindArrayRemote(array &$store): void
    {
        $this->app->singleton(BackupTransportFactory::class, function () use (&$store) {
            return new class($store) extends BackupTransportFactory
            {
                /** @param  array<string, string>  $store */
                public function __construct(private array &$store) {}

                public function make(BackupDestination $destination): BackupTransport
                {
                    if ($destination->kind === BackupDestinationKind::S3) {
                        return new class($this->store) implements BackupTransport
                        {
                            /** @param  array<string, string>  $store */
                            public function __construct(private array &$store) {}

                            public function put(string $name, string $absolutePath): string
                            {
                                $this->store[$name] = (string) file_get_contents($absolutePath);

                                return $name;
                            }

                            public function has(string $remoteId): bool
                            {
                                return isset($this->store[$remoteId]);
                            }

                            public function fetch(string $remoteId, string $destination): void
                            {
                                file_put_contents($destination, $this->store[$remoteId] ?? '');
                            }

                            public function delete(string $remoteId): void
                            {
                                unset($this->store[$remoteId]);
                            }

                            public function test(): bool
                            {
                                return true;
                            }
                        };
                    }

                    return parent::make($destination);
                }
            };
        });
    }
}
