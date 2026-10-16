<?php

namespace Tests\Feature\Backups;

use App\Actions\Backups\CreateFilesBackupAction;
use App\Actions\Backups\PromoteFilesBackupAction;
use App\Actions\Backups\VerifyBackupSnapshotAction;
use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupDestination;
use App\Models\BackupSnapshot;
use App\Models\User;
use App\Models\WorkflowAction;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression tests for RV-BUG-23 (promoting a files snapshot left branding
 * artwork in the restore-holding folder) and RV-PERF-17 (the Backups page
 * loaded every listed snapshot's full files manifest).
 */
class FilesPromoteAndBackupsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    private function promotedFilesSnapshot(): BackupSnapshot
    {
        $snapshot = app(CreateFilesBackupAction::class)->execute();
        $verified = app(VerifyBackupSnapshotAction::class)->execute($snapshot);

        return $verified->fresh() ?? $verified;
    }

    public function test_promote_restores_branding_artwork_and_sets_the_replaced_artwork_aside(): void
    {
        Storage::disk('local')->put('submissions/1/a.txt', 'A');
        Storage::disk('public')->put('branding/logo.png', 'ORIGINAL');
        $snapshot = $this->promotedFilesSnapshot();
        $this->assertContains('branding/logo.png', array_column($snapshot->manifestArray()['files'], 'path'));

        Storage::disk('public')->put('branding/logo.png', 'CHANGED');
        Storage::disk('public')->put('branding/added-later.png', 'NEW');

        app(PromoteFilesBackupAction::class)->execute($snapshot, $this->admin, (string) $snapshot->id);

        $this->assertSame('A', Storage::disk('local')->get('submissions/1/a.txt'));
        $this->assertSame('ORIGINAL', Storage::disk('public')->get('branding/logo.png'));
        Storage::disk('public')->assertMissing('branding/added-later.png');

        // The replaced artwork is kept, off the public disk.
        $previous = collect(Storage::disk('local')->allFiles())->filter(fn (string $path): bool => str_starts_with($path, 'branding-previous-'));
        $this->assertTrue($previous->contains(fn (string $path): bool => str_ends_with($path, '/added-later.png')));
        $this->assertSame([], Storage::disk('local')->allFiles('restore-holding'));
        $this->assertSame(BackupSnapshotStatus::Promoted, $snapshot->fresh()?->status);
    }

    public function test_promote_without_artwork_in_the_snapshot_keeps_current_branding(): void
    {
        Storage::disk('local')->put('submissions/1/a.txt', 'A');
        $snapshot = $this->promotedFilesSnapshot();

        Storage::disk('public')->put('branding/logo.png', 'CURRENT');

        app(PromoteFilesBackupAction::class)->execute($snapshot, $this->admin, (string) $snapshot->id);

        $this->assertSame('CURRENT', Storage::disk('public')->get('branding/logo.png'));
        $this->assertSame('A', Storage::disk('local')->get('submissions/1/a.txt'));
    }

    public function test_backups_page_lists_snapshots_without_loading_files_manifests(): void
    {
        $local = BackupDestination::factory()->create();
        $remote = BackupDestination::factory()->s3()->create();
        $files = array_map(fn (int $i): array => ['path' => "submissions/{$i}/file.pdf", 'sha256' => str_repeat('a', 64)], range(1, 500));
        $big = BackupSnapshot::factory()->create([
            'kind' => BackupSnapshotKind::Files,
            'destination_id' => $local->id,
            'path' => 'backups/files/big.zip',
            'manifest' => ['files' => $files, 'count' => count($files)],
        ]);
        BackupSnapshot::factory()->create([
            'kind' => BackupSnapshotKind::Files,
            'destination_id' => $remote->id,
            'path' => 'backups/files/big.zip',
        ]);
        WorkflowAction::factory()->count(3)->create();
        $database = BackupSnapshot::factory()->create([
            'kind' => BackupSnapshotKind::Database,
            'destination_id' => $local->id,
            'path' => 'backups/database/db.sql.gz',
            'manifest' => ['workflow_actions' => 1],
        ]);

        $selects = [];
        DB::listen(function ($query) use (&$selects): void {
            if (str_contains($query->sql, 'backup_snapshots')) {
                $selects[] = $query->sql;
            }
        });

        $component = Livewire::actingAs($this->admin)->test('pages::admin.backups')
            ->assertOk()
            ->assertSee('delete-local-'.$big->id, false)
            ->assertSee('2 workflow actions recorded after it', false);

        foreach ($component->instance()->snapshots as $snapshot) {
            $this->assertArrayNotHasKey('manifest', $snapshot->getAttributes());
        }
        $this->assertSame(2, $component->instance()->actionsLostBy($database));

        // No query reads every column, and the per-row "remote copy?" check is one query.
        $this->assertSame([], array_values(array_filter($selects, fn (string $sql): bool => preg_match('/select \* from [`"]backup_snapshots[`"]/', $sql) === 1)));
        $this->assertCount(1, array_filter($selects, fn (string $sql): bool => str_contains($sql, 'distinct')));
    }
}
