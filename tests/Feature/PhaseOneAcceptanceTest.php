<?php

namespace Tests\Feature;

use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\ActionRequiredNotification;
use App\Notifications\SubmissionDecidedNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class PhaseOneAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_level_approval_and_rejection_work_end_to_end(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();

        $admin = User::factory()->create()->assignRole('admin');
        $submitter = $this->createUserThroughAdministration(
            $admin,
            'Musa Submitter',
            'musa@example.test',
            'submitter',
        );
        $firstApprover = $this->createUserThroughAdministration(
            $admin,
            'Ada First Approver',
            'ada@example.test',
            'approver',
        );
        $secondApprover = $this->createUserThroughAdministration(
            $admin,
            'Bola Second Approver',
            'bola@example.test',
            'approver',
        );
        $finalApprover = $this->createUserThroughAdministration(
            $admin,
            'Chidi Final Approver',
            'chidi@example.test',
            'approver',
        );

        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-templates')
            ->call('createTemplate')
            ->set('name', 'Three-level company approval')
            ->set('description', 'Operational acceptance workflow')
            ->set('isDefault', true)
            ->set('steps', [
                $this->userStep('Department approval', $firstApprover),
                $this->userStep('Finance approval', $secondApprover),
                $this->userStep('Executive approval', $finalApprover),
            ])
            ->call('save')
            ->assertHasNoErrors();

        $template = WorkflowTemplate::query()
            ->where('name', 'Three-level company approval')
            ->firstOrFail();
        $this->assertSame(3, $template->steps()->count());

        $approved = $this->submitThroughWizard(
            $submitter,
            'Quarterly operating budget',
            'Approve the attached operating plan.',
        );

        $this->assertSame(SubmissionStatus::InReview, $approved->status);
        Notification::assertSentTo($firstApprover, ActionRequiredNotification::class);
        Notification::assertNotSentTo($secondApprover, ActionRequiredNotification::class);

        Notification::fake();
        $this->approveThroughPage($approved, $firstApprover, 'Department review complete.');
        Notification::assertSentTo($secondApprover, ActionRequiredNotification::class);

        Notification::fake();
        $this->approveThroughPage($approved, $secondApprover, 'Budget checks complete.');
        Notification::assertSentTo($finalApprover, ActionRequiredNotification::class);

        Notification::fake();
        $this->approveThroughPage($approved, $finalApprover, 'Executive approval granted.');
        Notification::assertSentTo(
            $submitter,
            SubmissionDecidedNotification::class,
            fn (SubmissionDecidedNotification $notification) => $notification->decision === SubmissionStatus::Approved,
        );

        $approved->refresh();
        $approvedInstance = $approved->workflowInstance()->with('actions')->firstOrFail();

        $this->assertSame(SubmissionStatus::Approved, $approved->status);
        $this->assertSame(WorkflowStatus::Completed, $approvedInstance->status);
        $this->assertSame(
            [$firstApprover->id, $secondApprover->id, $finalApprover->id],
            $approvedInstance->actions->pluck('actor_id')->all(),
        );
        $this->assertSame(
            ['Department review complete.', 'Budget checks complete.', 'Executive approval granted.'],
            $approvedInstance->actions->pluck('comment')->all(),
        );

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $approved])
            ->assertSee('Department review complete.')
            ->assertSee('Budget checks complete.')
            ->assertSee('Executive approval granted.');

        Notification::fake();
        $rejected = $this->submitThroughWizard(
            $submitter,
            'Unbudgeted equipment request',
            'Requesting equipment outside the approved plan.',
        );

        Livewire::actingAs($firstApprover)
            ->test('pages::submissions.show', ['submission' => $rejected])
            ->set('actionComment', 'No approved budget is available.')
            ->call('act', WorkflowActionType::Reject->value)
            ->assertHasNoErrors();

        Notification::assertSentTo(
            $submitter,
            SubmissionDecidedNotification::class,
            fn (SubmissionDecidedNotification $notification) => $notification->decision === SubmissionStatus::Rejected,
        );

        $rejected->refresh();
        $rejectedInstance = $rejected->workflowInstance()->with('actions')->firstOrFail();

        $this->assertSame(SubmissionStatus::Rejected, $rejected->status);
        $this->assertSame(WorkflowStatus::Rejected, $rejectedInstance->status);
        $this->assertCount(1, $rejectedInstance->actions);
        $this->assertSame(WorkflowActionType::Reject, $rejectedInstance->actions->first()->action);
        $this->assertSame('No approved budget is available.', $rejectedInstance->actions->first()->comment);

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $rejected])
            ->assertSee('No approved budget is available.');
    }

    private function createUserThroughAdministration(
        User $admin,
        string $name,
        string $email,
        string $role,
    ): User {
        Livewire::actingAs($admin)
            ->test('pages::admin.users')
            ->call('createUser')
            ->set('name', $name)
            ->set('email', $email)
            ->set('roles', [$role])
            ->set('password', 'Secure-password-123')
            ->set('password_confirmation', 'Secure-password-123')
            ->call('save')
            ->assertHasNoErrors();

        return User::query()->where('email', $email)->firstOrFail();
    }

    /** @return array{name: string, assigneeType: string, assigneeIds: array<int, int>} */
    private function userStep(string $name, User $approver): array
    {
        return [
            'name' => $name,
            'stepType' => 'sequential',
            'assigneeType' => 'user',
            'assigneeIds' => [$approver->id],
        ];
    }

    private function submitThroughWizard(User $submitter, string $title, string $body): Submission
    {
        Livewire::actingAs($submitter)
            ->test('pages::submissions.create')
            ->set('type', 'memo')
            ->set('title', $title)
            ->set('body', $body)
            ->call('next')
            ->call('submit')
            ->assertHasNoErrors();

        return Submission::query()->where('title', $title)->firstOrFail();
    }

    private function approveThroughPage(Submission $submission, User $approver, string $comment): void
    {
        Livewire::actingAs($approver)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->assertSee('Your decision')
            ->set('actionComment', $comment)
            ->call('act', WorkflowActionType::Approve->value)
            ->assertHasNoErrors();
    }
}
