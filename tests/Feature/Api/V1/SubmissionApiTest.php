<?php

namespace Tests\Feature\Api\V1;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
    }

    public function test_token_can_create_a_draft_submission_and_receive_a_versioned_resource(): void
    {
        Sanctum::actingAs($this->submitter, ['submissions:read', 'submissions:write']);

        $response = $this->postJson('/api/v1/submissions', [
            'type' => 'memo',
            'title' => 'API-created budget memo',
            'body' => '<p>Budget details</p>',
            'priority' => 'high',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'API-created budget memo')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.priority', 'high')
            ->assertJsonPath('data.request_type.slug', 'general-request')
            ->assertJsonPath('data.request_type.version', 1);

        $this->assertDatabaseHas('submissions', [
            'title' => 'API-created budget memo',
            'submitter_id' => $this->submitter->id,
            'status' => SubmissionStatus::Draft->value,
        ]);
        $this->assertDatabaseHas('submission_field_values', [
            'text_value' => '<p>Budget details</p>',
        ]);
    }

    public function test_token_can_create_and_immediately_submit_a_submission(): void
    {
        Sanctum::actingAs($this->submitter, ['submissions:read', 'submissions:write']);

        $response = $this->postJson('/api/v1/submissions', [
            'type' => 'request',
            'title' => 'API purchase request',
            'submit' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.workflow.status', 'active')
            ->assertJsonPath('data.workflow.current_step.name', 'Approval Level 1');
    }

    public function test_submission_index_is_token_scoped_and_filterable(): void
    {
        Submission::factory()->for($this->submitter, 'submitter')->create([
            'title' => 'My approved submission',
            'status' => SubmissionStatus::Approved,
        ]);
        Submission::factory()->create([
            'title' => 'Another user submission',
            'status' => SubmissionStatus::Rejected,
        ]);

        Sanctum::actingAs($this->submitter, ['submissions:read']);

        $this->getJson('/api/v1/submissions?status=approved')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'My approved submission');
    }

    public function test_assigned_approver_can_record_an_engine_backed_action(): void
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);
        app(SubmitSubmissionAction::class)->execute($submission);

        Sanctum::actingAs($this->approver, ['approvals:act']);

        $this->postJson('/api/v1/submissions/'.$submission->id.'/actions', [
            'action' => 'approve',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.workflow.status', 'completed')
            ->assertJsonPath('data.workflow.actions.0.action', 'approve');
    }

    public function test_assigned_approver_can_list_a_submission_requiring_their_action(): void
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);
        app(SubmitSubmissionAction::class)->execute($submission);

        Sanctum::actingAs($this->approver, ['submissions:read']);

        $this->getJson('/api/v1/submissions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $submission->id);
    }

    public function test_missing_token_ability_is_rejected(): void
    {
        Sanctum::actingAs($this->submitter, ['submissions:read']);

        $this->postJson('/api/v1/submissions', [
            'type' => 'memo',
            'title' => 'Should not be created',
        ])->assertForbidden();
    }

    public function test_current_user_endpoint_returns_token_abilities(): void
    {
        $token = $this->submitter->createToken('Read-only API token', ['submissions:read'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.id', $this->submitter->id)
            ->assertJsonPath('data.token_abilities.0', 'submissions:read');
    }

    public function test_deactivated_user_token_is_rejected(): void
    {
        $inactiveUser = User::factory()->create(['is_active' => false])->assignRole('submitter');
        Sanctum::actingAs($inactiveUser, ['submissions:read']);

        $this->getJson('/api/v1/user')
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account has been deactivated.');
    }

    public function test_submission_detail_allows_token_with_submissions_read(): void
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);

        Sanctum::actingAs($this->submitter, ['submissions:read']);

        $this->getJson('/api/v1/submissions/'.$submission->id)->assertOk();
    }

    public function test_submission_detail_rejects_token_with_only_submissions_write(): void
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);

        Sanctum::actingAs($this->submitter, ['submissions:write']);

        $this->getJson('/api/v1/submissions/'.$submission->id)->assertForbidden();
    }

    public function test_submission_detail_rejects_token_with_only_approvals_act(): void
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);

        Sanctum::actingAs($this->submitter, ['approvals:act']);

        $this->getJson('/api/v1/submissions/'.$submission->id)->assertForbidden();
    }

    public function test_submission_detail_rejects_token_with_no_abilities(): void
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);

        Sanctum::actingAs($this->submitter, []);

        $this->getJson('/api/v1/submissions/'.$submission->id)->assertForbidden();
    }

    public function test_submission_detail_rejects_approver_who_cannot_view_draft(): void
    {
        $submission = Submission::factory()->for($this->submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);

        Sanctum::actingAs($this->approver, ['submissions:read']);

        $this->getJson('/api/v1/submissions/'.$submission->id)->assertForbidden();
    }
}
