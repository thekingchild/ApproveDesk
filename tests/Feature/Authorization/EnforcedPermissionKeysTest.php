<?php

namespace Tests\Feature\Authorization;

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * RV-SEC-13: catalogue permissions shown in the role editor are enforced, and
 * the upgrade backfill keeps existing roles' abilities.
 */
class EnforcedPermissionKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_create_comments_and_send_reminders_are_enforced_except_for_the_requester(): void
    {
        $role = Role::create(['name' => 'read-only-reviewer', 'guard_name' => 'web']);
        $role->givePermissionTo(['submissions.view.all']);
        $reviewer = User::factory()->create()->assignRole($role);
        $requester = User::factory()->create();
        $submission = Submission::factory()->create(['submitter_id' => $requester->id]);

        $this->assertTrue($reviewer->can('view', $submission));
        $this->assertFalse($reviewer->can('comment', $submission));
        $this->assertFalse($reviewer->can('nudge', $submission));

        $role->givePermissionTo(['comments.create', 'reminders.send']);
        $reviewer = $reviewer->fresh();
        $this->assertTrue($reviewer->can('comment', $submission));
        $this->assertTrue($reviewer->can('nudge', $submission));

        $this->assertTrue($requester->can('comment', $submission), 'Requesters can always discuss their own request.');
        $this->assertTrue($requester->can('nudge', $submission));
        $this->assertTrue($requester->can('view', $submission), 'Requesters always see their own request.');
    }

    public function test_withdraw_requires_the_withdraw_permission(): void
    {
        $role = Role::create(['name' => 'submit-only', 'guard_name' => 'web']);
        $role->givePermissionTo(['submissions.create', 'submissions.view.own']);
        $requester = User::factory()->create()->assignRole($role);
        $submission = Submission::factory()->create(['submitter_id' => $requester->id, 'status' => SubmissionStatus::InReview]);

        $this->assertFalse($requester->can('withdraw', $submission));
        $this->assertTrue(User::factory()->create()->assignRole('submitter')->can('withdraw', Submission::factory()->create([
            'submitter_id' => User::query()->latest('id')->value('id'),
            'status' => SubmissionStatus::InReview,
        ])));
    }

    public function test_evidence_export_requires_the_export_permission(): void
    {
        $role = Role::create(['name' => 'org-viewer', 'guard_name' => 'web']);
        $role->givePermissionTo(['submissions.view.all']);
        $viewer = User::factory()->create()->assignRole($role);
        $submission = Submission::factory()->create();

        $this->assertFalse($viewer->can('downloadEvidence', $submission));
        $this->assertTrue(User::factory()->create()->assignRole('department-head')->can('downloadEvidence', $submission));
    }

    public function test_managing_auditor_access_requires_its_own_permission(): void
    {
        $role = Role::create(['name' => 'settings-only', 'guard_name' => 'web']);
        $role->givePermissionTo(['settings.manage']);
        $manager = User::factory()->create()->assignRole($role);
        $auditor = User::factory()->create()->assignRole('auditor');

        Livewire::actingAs($manager)
            ->test('pages::admin.records')
            ->set('auditorUserId', $auditor->id)
            ->call('grantAccess')
            ->assertForbidden();
    }

    public function test_upgrade_backfill_keeps_existing_custom_roles_working(): void
    {
        $custom = Role::create(['name' => 'legacy-team', 'guard_name' => 'web']);
        $custom->givePermissionTo(['submissions.create', 'submissions.view.all', 'settings.manage']);

        (require database_path('migrations/2026_10_16_130000_backfill_enforced_permission_keys.php'))->up();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $held = $custom->fresh()->permissions->pluck('name')->all();
        foreach (['submissions.view.own', 'submissions.withdraw', 'comments.create', 'reminders.send', 'audit.evidence.export', 'audit.auditor_access.manage'] as $key) {
            $this->assertContains($key, $held, "Backfill should grant {$key}.");
        }

        $auditorHeld = Role::findByName('auditor')->permissions->pluck('name')->all();
        $this->assertNotContains('comments.create', $auditorHeld);
        $this->assertContains('audit.evidence.export', $auditorHeld);
    }
}
