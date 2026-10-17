<?php

namespace Tests\Feature\Submissions;

use App\Actions\Submissions\ResubmitSubmissionAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Enums\TimelineStepState;
use App\Enums\WorkflowActionType;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SystemOwner;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\StepOutcomes;
use App\Services\Workflow\WorkflowTimeline;
use App\Services\WorkflowEngine;
use App\Support\SubmissionFileLinks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** RV-ARC-02: rules the request page used to hold now live in the engine, Actions and services. */
class RequestPageRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $requester;

    private User $approver;

    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->requester = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create()->assignRole('approver');
        $this->second = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver, $this->second])->create();
    }

    public function test_timeline_reports_each_steps_state_and_progress(): void
    {
        $submission = $this->submitted();

        $timeline = app(WorkflowTimeline::class)->for($submission->fresh());
        $this->assertSame([TimelineStepState::Active, TimelineStepState::Pending], array_column($timeline, 'state'));
        $this->assertSame([0, 1], [$timeline[0]['approvals'], $timeline[0]['required']]);

        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->first(), $this->approver, WorkflowActionType::Approve);
        $timeline = app(WorkflowTimeline::class)->for($submission->fresh());
        $this->assertSame([TimelineStepState::Done, TimelineStepState::Active], array_column($timeline, 'state'));
        $this->assertSame(1, $timeline[0]['approvals']);
        $this->assertNotNull($timeline[0]['acted_at']);

        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->first(), $this->second, WorkflowActionType::Reject, 'Over budget.');
        $timeline = app(WorkflowTimeline::class)->for($submission->fresh());
        $this->assertSame(TimelineStepState::Rejected, $timeline[1]['state']);
    }

    public function test_the_comment_rule_has_one_source(): void
    {
        foreach (app(StepOutcomes::class)->forVersion(null) as $outcome) {
            $this->assertSame(WorkflowActionType::from($outcome['key'])->requiresComment(), $outcome['requires_comment'], $outcome['key']);
        }

        $submission = $this->submitted();
        $this->expectException(WorkflowException::class);
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->first(), $this->approver, WorkflowActionType::Return);
    }

    public function test_the_engine_refuses_the_hidden_system_owner_as_a_delegate(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        $submission = $this->submitted();

        $this->expectException(WorkflowException::class);
        app(WorkflowEngine::class)->delegate($submission->workflowInstance()->first(), $this->approver, $owner, now()->addDay());
    }

    public function test_the_page_shows_the_engines_reason_for_a_refused_delegation(): void
    {
        $delegate = User::factory()->create()->assignRole('approver');
        $submission = $this->submitted();

        Livewire::actingAs($this->approver)->test('submissions.decision', ['submission' => $submission])
            ->set('delegateUserId', (string) $delegate->id)
            ->set('delegationExpiresAt', now()->subHour()->format('Y-m-d\TH:i'))
            ->call('delegateApproval')
            ->assertHasErrors(['delegateUserId'])
            ->assertSee('the expiry must be in the future');

        $this->assertSame(0, $submission->workflowInstance->delegations()->count());
    }

    public function test_an_information_request_needs_an_answer_whichever_way_it_is_sent(): void
    {
        $submission = $this->awaitingInformation();

        try {
            app(ResubmitSubmissionAction::class)->execute($submission, $this->requester, '  ');
            $this->fail('A blank answer was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('comment', $exception->errors());
        }

        Livewire::actingAs($this->requester)->test('pages::submissions.show', ['submission' => $submission])
            ->call('resubmit')
            ->assertHasErrors(['resubmissionComment']);

        Livewire::actingAs($this->requester)->test('pages::submissions.show', ['submission' => $submission])
            ->set('resubmissionComment', 'Cost centre 4410')
            ->call('resubmit')
            ->assertHasNoErrors();

        $this->assertSame(SubmissionStatus::InReview, $submission->fresh()->status);
        $this->assertSame(['Cost centre 4410'], $submission->comments()->pluck('body')->all());
    }

    public function test_only_someone_who_may_submit_can_resubmit(): void
    {
        $submission = $this->awaitingInformation();

        $this->expectException(AuthorizationException::class);
        app(ResubmitSubmissionAction::class)->execute($submission, $this->approver, 'Not mine to answer.');
    }

    public function test_file_links_are_signed_with_one_lifetime_each(): void
    {
        $file = SubmissionFile::factory()->create();

        foreach ([SubmissionFileLinks::download($file), SubmissionFileLinks::preview($file)] as $link) {
            $this->assertTrue(URL::hasValidSignature(Request::create($link)));
            $this->assertStringContainsString('/submissions/'.$file->submission_id.'/', $link);
        }
        $this->assertStringContainsString('preview=1', SubmissionFileLinks::preview($file));
        $this->assertStringNotContainsString('preview=1', SubmissionFileLinks::download($file));
    }

    /** @return array<string, array{string, string}> */
    public static function helpers(): array
    {
        return [
            'file link' => ['submissions.attachments', 'fileUrl'],
            'preview link' => ['submissions.attachments', 'previewUrl'],
            'comment display' => ['submissions.comments', 'displayComment'],
            'sanitizer' => ['pages::submissions.show', 'safeHtml'],
            'field display' => ['pages::submissions.show', 'fieldDisplayValue'],
        ];
    }

    #[DataProvider('helpers')]
    public function test_template_helpers_are_not_browser_endpoints(string $component, string $method): void
    {
        $submission = $this->submitted();

        $this->expectException(MethodNotFoundException::class);
        Livewire::actingAs($this->requester)->test($component, ['submission' => $submission])->call($method, 1);
    }

    private function submitted(): Submission
    {
        $submission = Submission::factory()->create(['submitter_id' => $this->requester->id, 'status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);

        return $submission->fresh();
    }

    private function awaitingInformation(): Submission
    {
        $submission = $this->submitted();
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->first(), $this->approver, WorkflowActionType::RequestInformation, 'Which cost centre?');

        return $submission->fresh();
    }
}
