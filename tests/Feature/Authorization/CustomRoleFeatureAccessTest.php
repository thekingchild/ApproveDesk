<?php

namespace Tests\Feature\Authorization;

use App\Models\Submission;
use App\Models\SubmissionWatcher;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Roles\RolePermissionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class CustomRoleFeatureAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $this->owner->id]);
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_backup_viewer_can_open_page_but_cannot_create_backup(): void
    {
        $role = app(RolePermissionService::class)->create(
            $this->owner,
            'Backup viewer',
            null,
            ['backups.view'],
        );
        $user = User::factory()->create()->assignRole($role);

        $this->actingAs($user)->get(route('admin.backups.index'))->assertOk();

        Livewire::actingAs($user)
            ->test('admin.backups.snapshots')
            ->call('backupDatabase')
            ->assertForbidden();
    }

    public function test_backup_operator_can_create_but_cannot_promote(): void
    {
        $role = app(RolePermissionService::class)->create(
            $this->owner,
            'Backup operator',
            null,
            ['backups.view', 'backups.create'],
        );
        $user = User::factory()->create()->assignRole($role);

        Livewire::actingAs($user)
            ->test('admin.backups.snapshots')
            ->call('backupDatabase')
            ->assertHasNoErrors();

        $this->assertFalse($user->can('backups.promote'));
    }

    public function test_follower_manager_needs_existing_request_access(): void
    {
        $role = app(RolePermissionService::class)->create(
            $this->owner,
            'Follower manager',
            null,
            ['submissions.view.own', 'followers.manage'],
        );
        $manager = User::factory()->create()->assignRole($role);
        $requester = User::factory()->create();
        $target = User::factory()->create();
        $submission = Submission::factory()->for($requester, 'submitter')->create();

        $this->assertFalse($manager->can('addWatcher', $submission));

        SubmissionWatcher::factory()->create([
            'submission_id' => $submission->id,
            'user_id' => $manager->id,
            'added_by_id' => $requester->id,
        ]);

        $this->assertTrue($manager->can('addWatcher', $submission));
        $this->assertTrue($manager->can('view', $submission));
        $this->assertFalse($target->can('view', $submission));
    }

    public function test_permission_dependencies_are_enforced(): void
    {
        $this->expectException(ValidationException::class);

        app(RolePermissionService::class)->create(
            $this->owner,
            'Unsafe backup role',
            null,
            ['backups.create'],
        );
    }
}
