<?php

namespace Tests\Feature\Operations;

use App\Actions\Backups\RetainBackupSnapshotsAction;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupSnapshot;
use App\Models\SystemOwner;
use App\Models\Theme;
use App\Models\User;
use App\Services\Themes\ThemeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Low-severity review findings, second batch: RV-DOC-03, RV-PERF-20, RV-OPS-07. */
class LowSeverityBatchTwoTest extends TestCase
{
    use RefreshDatabase;

    public function test_rv_doc_03_only_the_system_owner_may_edit_an_administrator(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        $admin = User::factory()->create()->assignRole('admin');
        $peer = User::factory()->create()->assignRole('admin');
        $member = User::factory()->create()->assignRole('submitter');

        $this->assertFalse($admin->can('update', $peer), 'The Edit button would show but the save be refused.');
        $this->assertTrue($admin->can('update', $member));
        $this->assertTrue($owner->can('update', $peer));

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($peer->name);
    }

    public function test_rv_perf_20_theme_resolution_is_cached_until_a_theme_changes(): void
    {
        $service = app(ThemeService::class);
        $current = $service->effectiveFor(null);

        DB::enableQueryLog();
        $service->effectiveFor(null);
        $service->effectiveFor(null);
        $this->assertSame([], array_filter(DB::getQueryLog(), fn (array $query): bool => str_contains($query['query'], 'themes')));
        DB::disableQueryLog();

        // A change to any theme drops the cached list.
        $other = Theme::factory()->create(['is_enabled' => true, 'is_default' => false]);
        Theme::query()->whereKey($current->id)->firstOrFail()->update(['is_default' => false]);
        $other->update(['is_default' => true]);
        $this->assertSame($other->id, $service->effectiveFor(null)->id);
    }

    public function test_rv_ops_07_failed_backups_are_pruned_after_thirty_days(): void
    {
        $old = BackupSnapshot::factory()->create(['status' => BackupSnapshotStatus::Failed, 'path' => null]);
        $old->forceFill(['created_at' => now()->subDays(RetainBackupSnapshotsAction::FAILED_KEEP_DAYS + 1)])->save();
        $recent = BackupSnapshot::factory()->create(['status' => BackupSnapshotStatus::Failed, 'path' => null]);

        app(RetainBackupSnapshotsAction::class)->execute();

        $this->assertNull($old->fresh());
        $this->assertNotNull($recent->fresh());
    }
}
