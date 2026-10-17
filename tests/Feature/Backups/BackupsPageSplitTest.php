<?php

namespace Tests\Feature\Backups;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupDestination;
use App\Models\BackupRestoreEvent;
use App\Models\BackupSnapshot;
use App\Models\User;
use App\Services\Backups\BackupInventory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

/** ADR 0006 split of the Backups page, and the RV-ARC-09 fixes that came with it. */
class BackupsPageSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    public function test_rv_arc_09_viewing_the_page_does_not_write_the_local_destination(): void
    {
        $this->actingAs($this->admin)->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee('Local disk')
            ->assertSee('Restore history');

        $this->assertSame(0, BackupDestination::query()->count());
    }

    public function test_rv_arc_09_stored_bytes_count_each_file_once_and_skip_failures(): void
    {
        $local = BackupDestination::factory()->create();
        $remote = BackupDestination::factory()->s3()->create();
        $snapshot = fn (array $attributes) => BackupSnapshot::factory()->create($attributes + ['kind' => BackupSnapshotKind::Database]);
        $snapshot(['destination_id' => $local->id, 'path' => 'backups/database/a.sql.gz', 'bytes' => 100]);
        $snapshot(['destination_id' => $remote->id, 'path' => 'backups/database/a.sql.gz', 'bytes' => 100]);
        $snapshot(['destination_id' => $local->id, 'path' => 'backups/database/b.sql.gz', 'bytes' => 50]);
        $snapshot(['destination_id' => $local->id, 'path' => 'backups/database/c.sql.gz', 'bytes' => 999, 'status' => BackupSnapshotStatus::Failed]);
        BackupSnapshot::factory()->create(['kind' => BackupSnapshotKind::Files, 'destination_id' => $local->id, 'path' => 'backups/files/a.zip', 'bytes' => 7]);

        $inventory = app(BackupInventory::class);

        $this->assertSame(150, $inventory->storedBytes(BackupSnapshotKind::Database));
        $this->assertSame(7, $inventory->storedBytes(BackupSnapshotKind::Files));
    }

    public function test_rv_lar_16_the_download_url_helper_is_not_an_endpoint(): void
    {
        $snapshot = BackupSnapshot::factory()->create(['destination_id' => BackupDestination::factory()->create()->id]);

        $component = Livewire::actingAs($this->admin)->test('admin.backups.snapshots')
            ->assertSee('/admin/backups/'.$snapshot->id.'/download', false);

        $this->expectException(MethodNotFoundException::class);
        $component->call('downloadUrl', $snapshot->id);
    }

    public function test_a_snapshot_change_tells_the_other_sections_to_refresh(): void
    {
        Livewire::actingAs($this->admin)->test('admin.backups.snapshots')
            ->call('backupDatabase')
            ->assertHasNoErrors()
            ->assertDispatched('backups-changed');

        $trail = Livewire::actingAs($this->admin)->test('admin.backups.restore-history')
            ->assertSee('No snapshots have been restored yet.');

        BackupRestoreEvent::query()->create([
            'kind' => BackupSnapshotKind::Database,
            'snapshot_name' => 'db-restored-name',
            'actor_name' => 'Ada Admin',
        ]);

        $trail->dispatch('backups-changed')->assertSee('db-restored-name');
    }

    public function test_each_section_requires_the_view_permission(): void
    {
        $member = User::factory()->create();

        foreach (['schedule', 'snapshots', 'destinations', 'restore-history'] as $section) {
            Livewire::actingAs($member)->test('admin.backups.'.$section)->assertForbidden();
        }
    }
}
