<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\PlaceLegalHoldAction;
use App\Actions\Audit\ReleaseLegalHoldAction;
use App\Actions\Audit\RequestDispositionAction;
use App\Actions\Audit\ReviewDispositionAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\DTOs\WorkflowActionData;
use App\Enums\DispositionStatus;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Jobs\ApplyRetentionJob;
use App\Models\DispositionReview;
use App\Models\RequestTypeVersion;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Audit\RetentionPolicy;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-PERF-16 (retention skipped rows while soft-deleting; no timeout),
 * RV-DATA-08 (retention retires, never purges) and RV-BUG-26 (disposition on
 * unfinished requests; Records errors surfaced as 500s).
 */
class RetentionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $secondAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->secondAdmin = User::factory()->create()->assignRole('admin');
        Setting::put('retention.draft_days', '30');
        Setting::put('retention.completed_days', '90');
    }

    public function test_retention_retires_every_eligible_draft_beyond_the_first_page(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        // More rows than one offset page (each() used 1,000), so the old paging skipped the tail.
        $count = 1050;
        $submitter = User::factory()->create();
        $versionId = RequestTypeVersion::general()->id;
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'reference_number' => 'RET-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'type' => 'request',
                'request_type_version_id' => $versionId,
                'title' => 'Old draft '.$i,
                'submitter_id' => $submitter->id,
                'priority' => 'normal',
                'status' => SubmissionStatus::Draft->value,
                'created_at' => now()->subDays(40),
                'updated_at' => now()->subDays(40),
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('submissions')->insert($chunk);
        }

        (new ApplyRetentionJob)->handle(app(RetentionPolicy::class));

        $this->assertSame(0, Submission::query()->where('title', 'like', 'Old draft %')->count());
        $this->assertSame($count, Submission::onlyTrashed()->where('title', 'like', 'Old draft %')->count());
    }

    public function test_retention_job_stops_before_the_worker_timeout(): void
    {
        $job = new ApplyRetentionJob;

        $this->assertLessThan(90, $job->timeout);
        $this->assertLessThan($job->timeout, ApplyRetentionJob::TIME_BUDGET_SECONDS);
    }

    public function test_retired_requests_keep_their_record_and_workflow_history(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create(['status' => SubmissionStatus::Draft]);
        app(SubmitSubmissionAction::class)->execute($submission);
        app(WorkflowEngine::class)->processAction($submission->workflowInstance()->firstOrFail(), new WorkflowActionData($approver, WorkflowActionType::Approve));
        $this->assertSame(SubmissionStatus::Approved, $submission->fresh()->status);
        $actions = DB::table('workflow_actions')->count();
        $this->assertGreaterThan(0, $actions);

        DB::table('submissions')->where('id', $submission->id)->update(['updated_at' => now()->subDays(120)]);
        $review = app(RequestDispositionAction::class)->execute($this->admin, $submission->fresh(), 'Past retention');
        app(ReviewDispositionAction::class)->execute($this->secondAdmin, $review, DispositionStatus::Approved);

        (new ApplyRetentionJob)->handle(app(RetentionPolicy::class));

        $this->assertSoftDeleted($submission);
        $this->assertNotNull(Submission::withTrashed()->find($submission->id));
        $this->assertSame($actions, DB::table('workflow_actions')->count());
        $this->assertDatabaseHas('workflow_instances', ['submission_id' => $submission->id]);
    }

    public function test_disposition_is_refused_for_an_unfinished_request(): void
    {
        foreach ([SubmissionStatus::Draft, SubmissionStatus::Pending, SubmissionStatus::InReview, SubmissionStatus::Returned, SubmissionStatus::AwaitingInformation] as $status) {
            $submission = Submission::factory()->create(['status' => $status]);

            try {
                app(RequestDispositionAction::class)->execute($this->admin, $submission);
                $this->fail('Disposition should be refused for '.$status->value);
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('finished', $exception->getMessage());
            }
        }

        $this->assertSame(0, DispositionReview::query()->count());

        $pending = Submission::factory()->create(['status' => SubmissionStatus::Pending]);
        Livewire::actingAs($this->admin)
            ->test('pages::admin.records')
            ->set('dispositionReference', $pending->reference_number)
            ->call('requestDisposition')
            ->assertHasErrors('dispositionReference');

        $this->assertSame(0, DispositionReview::query()->count());
    }

    public function test_a_review_cannot_approve_disposal_of_a_request_that_was_reopened(): void
    {
        $submission = Submission::factory()->create(['status' => SubmissionStatus::Rejected]);
        $review = app(RequestDispositionAction::class)->execute($this->admin, $submission);
        $submission->forceFill(['status' => SubmissionStatus::Pending])->saveQuietly();

        $this->expectException(InvalidArgumentException::class);
        app(ReviewDispositionAction::class)->execute($this->secondAdmin, $review, DispositionStatus::Approved);
    }

    public function test_records_screen_shows_review_and_hold_refusals_as_messages_not_errors(): void
    {
        $submission = Submission::factory()->create(['status' => SubmissionStatus::Approved]);
        $review = app(RequestDispositionAction::class)->execute($this->admin, $submission);
        $hold = app(PlaceLegalHoldAction::class)->execute($this->admin, $submission, 'Litigation');

        $page = Livewire::actingAs($this->admin)->test('pages::admin.records');

        // Decided by someone else while this page was open.
        app(ReviewDispositionAction::class)->execute($this->secondAdmin, $review, DispositionStatus::Rejected);
        app(ReleaseLegalHoldAction::class)->execute($this->secondAdmin, $hold);

        $page->call('approveDisposition', $review->id)->assertHasErrors('dispositionReview');
        $page->call('releaseHold', $hold->id)->assertHasErrors('holdRelease');

        $this->assertSame(DispositionStatus::Rejected, $review->fresh()->status);
    }
}
