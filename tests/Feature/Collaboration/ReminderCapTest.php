<?php

namespace Tests\Feature\Collaboration;

use App\Actions\Collaboration\NudgeApproversAction;
use App\DTOs\NudgeResult;
use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Exceptions\CollaborationException;
use App\Models\Submission;
use App\Models\SubmissionNudge;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Notifications\SubmissionNudgeNotification;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-BUG-19: the daily reminder cap counts reminder actions by the sender,
 * not recipients, and a recently reminded approver is skipped (and reported)
 * instead of blocking the reminder for everyone else.
 */
class ReminderCapTest extends TestCase
{
    use RefreshDatabase;

    private User $submitter;

    /** @var Collection<int, User> */
    private Collection $approvers;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $this->submitter = User::factory()->create()->assignRole('submitter');
        $this->approvers = User::factory()->count(4)->create();
        $template = WorkflowTemplate::factory()->default()->create();
        WorkflowTemplateStep::factory()->forTemplate($template, 1)->create([
            'name' => 'Parallel',
            'step_type' => StepType::Parallel,
            'assignee_type' => AssigneeType::User,
            'assignee_ids' => $this->approvers->pluck('id')->all(),
        ]);
        $this->submission = Submission::factory()->for($this->submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($this->submission);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nudge(): NudgeResult
    {
        return app(NudgeApproversAction::class)->execute($this->submission->fresh(), $this->submitter, 'Please');
    }

    public function test_step_with_more_approvers_than_the_cap_can_be_reminded(): void
    {
        $result = $this->nudge();

        $this->assertCount(4, $result->nudges);
        $this->assertTrue($result->skipped->isEmpty());
        $this->assertDatabaseCount('submission_nudges', 4);
        $this->assertSame(1, SubmissionNudge::query()->distinct()->count('batch_id'));
        foreach ($this->approvers as $approver) {
            Notification::assertSentTo($approver, SubmissionNudgeNotification::class);
        }
    }

    public function test_cap_counts_reminder_actions_not_recipients(): void
    {
        Carbon::setTestNow('2026-10-17 09:00:00');
        $this->nudge();
        Carbon::setTestNow('2026-10-17 10:01:00');
        $this->nudge();
        Carbon::setTestNow('2026-10-17 11:02:00');
        $this->nudge();
        $this->assertDatabaseCount('submission_nudges', 12);

        Carbon::setTestNow('2026-10-17 12:03:00');
        try {
            $this->nudge();
            $this->fail('A fourth reminder in 24 hours should be refused.');
        } catch (CollaborationException $exception) {
            $this->assertStringContainsString('at most 3 reminders', $exception->getMessage());
        }

        // The window rolls: a day after the first reminder there is room again.
        Carbon::setTestNow('2026-10-18 09:00:01');
        $this->assertCount(4, $this->nudge()->nudges);
    }

    public function test_recently_reminded_approver_is_skipped_not_blocking(): void
    {
        $other = User::factory()->create()->assignRole('submitter');
        SubmissionNudge::query()->create([
            'submission_id' => $this->submission->id,
            'actor_id' => $other->id,
            'recipient_id' => $this->approvers[0]->id,
            'step_id' => $this->submission->workflowInstance()->firstOrFail()->current_step_id,
            'scheduled_for' => now(),
            'delayed_for_quiet_hours' => false,
        ]);

        $result = $this->nudge();

        $this->assertCount(3, $result->nudges);
        $this->assertSame([$this->approvers[0]->id], $result->skipped->pluck('id')->all());
        $this->assertFalse($result->nudges->pluck('recipient_id')->contains($this->approvers[0]->id));
    }

    public function test_reminder_is_refused_when_everyone_was_reminded_recently(): void
    {
        $this->nudge();

        $this->expectException(CollaborationException::class);
        $this->expectExceptionMessage('already reminded in the last 60 minutes');
        $this->nudge();
    }

    public function test_request_page_names_skipped_approvers(): void
    {
        $other = User::factory()->create()->assignRole('submitter');
        SubmissionNudge::query()->create([
            'submission_id' => $this->submission->id,
            'actor_id' => $other->id,
            'recipient_id' => $this->approvers[0]->id,
            'step_id' => $this->submission->workflowInstance()->firstOrFail()->current_step_id,
            'scheduled_for' => now(),
            'delayed_for_quiet_hours' => false,
        ]);

        Livewire::actingAs($this->submitter)
            ->test('pages::submissions.show', ['submission' => $this->submission])
            ->call('sendNudge')
            ->assertHasNoErrors()
            ->assertDispatched('toast-show', fn (string $event, array $params): bool => str_contains(
                (string) ($params['slots']['text'] ?? ''),
                $this->approvers[0]->name,
            ));

        $this->assertDatabaseCount('submission_nudges', 4);
    }
}
