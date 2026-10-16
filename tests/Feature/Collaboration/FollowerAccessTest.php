<?php

namespace Tests\Feature\Collaboration;

use App\Actions\Audit\GrantAuditorAccessAction;
use App\Actions\Audit\RevokeAuditorAccessAction;
use App\Actions\Collaboration\FollowSubmissionAction;
use App\Exceptions\CollaborationException;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RV-SEC-08: following a request must not turn temporary access into
 * permanent access.
 */
class FollowerAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_read_only_auditor_cannot_follow_and_loses_access_when_the_grant_ends(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $auditor = User::factory()->create()->assignRole('auditor');
        $submission = Submission::factory()->create();
        $access = app(GrantAuditorAccessAction::class)->execute($admin, $auditor, now()->subHour(), now()->addHour(), 'Annual audit');

        try {
            app(FollowSubmissionAction::class)->execute($submission, $auditor, $auditor);
            $this->fail('A read-only auditor followed a request.');
        } catch (CollaborationException) {
            // expected
        }

        app(RevokeAuditorAccessAction::class)->execute($admin, $access);

        $this->assertFalse($auditor->fresh()->can('view', $submission->fresh()));
        $this->actingAs($auditor)->get(route('submissions.approval-report.show', $submission))->assertForbidden();
    }

    public function test_temporary_delegate_who_followed_loses_access_when_the_delegation_ends(): void
    {
        $approver = User::factory()->create();
        $delegate = User::factory()->create()->assignRole('submitter');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();
        $instance = app(WorkflowEngine::class)->initialize($submission);
        app(WorkflowEngine::class)->delegate($instance, $approver, $delegate, now()->addHour());

        app(FollowSubmissionAction::class)->execute($submission, $delegate, $delegate);
        $this->assertTrue($submission->fresh()->isWatchedBy($delegate));

        Carbon::setTestNow(now()->addHours(2));

        $this->assertFalse($delegate->fresh()->can('view', $submission->fresh()));
    }

    public function test_follower_invited_by_the_requester_can_view(): void
    {
        $requester = User::factory()->create()->assignRole('submitter');
        $colleague = User::factory()->create()->assignRole('submitter');
        $submission = Submission::factory()->create(['submitter_id' => $requester->id]);

        app(FollowSubmissionAction::class)->execute($submission, $requester, $colleague);

        $this->assertTrue($colleague->can('view', $submission->fresh()));
    }
}
