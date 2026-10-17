<?php

namespace Tests\Feature\Authorization;

use App\Actions\WorkflowTemplates\RestoreWorkflowTemplateVersionAction;
use App\Enums\SystemRole;
use App\Models\AuditorAccess;
use App\Models\Submission;
use App\Models\SystemOwner;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** ADR 0008 follow-ups: named Gates instead of role names, and entry points that authorize. */
class NamedGatesAndEntryPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_adr_0008_named_gates_follow_the_owner_row_and_built_in_roles(): void
    {
        $owner = User::factory()->create()->assignRole(SystemRole::SuperAdmin->value);
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        $admin = User::factory()->create()->assignRole(SystemRole::Admin->value);
        $approver = User::factory()->create()->assignRole('approver');

        foreach (['system_owner', 'approval_reference.manage', 'dashboard.admin'] as $ability) {
            $this->assertTrue($owner->can($ability), "Owner: {$ability}");
            $this->assertFalse($approver->can($ability), "Approver: {$ability}");
        }

        $this->assertTrue($admin->can('dashboard.admin'));
        $this->assertFalse($admin->can('system_owner'));
        $this->assertFalse($admin->can('approval_reference.manage'), 'Approval ID format stays owner-only.');
    }

    public function test_adr_0008_restoring_a_workflow_version_is_authorized_inside_the_action(): void
    {
        $template = WorkflowTemplate::factory()->sequential([User::factory()->create()])->create();
        $version = $template->publishedVersion;
        $this->assertNotNull($version);

        $this->expectException(AuthorizationException::class);

        app(RestoreWorkflowTemplateVersionAction::class)->execute($version, User::factory()->create()->assignRole('approver'));
    }

    public function test_adr_0008_request_page_actions_check_their_ability_first(): void
    {
        $auditor = User::factory()->create()->assignRole(SystemRole::Auditor->value);
        AuditorAccess::query()->create([
            'user_id' => $auditor->id,
            'granted_by' => User::factory()->create()->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
            'reason' => 'Annual audit',
        ]);
        $submission = Submission::factory()->create();

        $this->assertTrue($auditor->can('view', $submission), 'Fixture: the auditor can open the request.');
        // A read-only auditor may look but not follow, remind, or mention.
        $calls = [
            ['submissions.followers', 'follow'],
            ['pages::submissions.show', 'sendNudge'],
            ['submissions.followers', 'saveWatcherPreferences'],
            ['submissions.comments', 'addMention', $auditor->id],
        ];
        foreach ($calls as $call) {
            $component = array_shift($call);
            Livewire::actingAs($auditor)
                ->test($component, ['submission' => $submission])
                ->call(...$call)
                ->assertForbidden();
        }
    }
}
