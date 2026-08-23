<?php

namespace Tests\Feature\Approvals;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Notifications\ActionRequiredNotification;
use App\Services\WorkflowEngine;
use App\Support\EmailApprovalLinks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class EmailApprovalTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $submitter = User::factory()->create()->assignRole('submitter');
        $this->approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();

        $this->submission = Submission::factory()->for($submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);
        app(SubmitSubmissionAction::class)->execute($this->submission);
    }

    public function test_signed_confirmation_page_does_not_mutate_workflow_and_signed_post_approves(): void
    {
        $instance = $this->submission->workflowInstance()->with('currentStep')->firstOrFail();
        $expiresAt = now()->addHours(12);
        $confirmationUrl = EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $this->approver, $expiresAt);
        $approvalUrl = EmailApprovalLinks::approvalUrl($instance, $instance->currentStep, $this->approver, $expiresAt);

        $this->get($confirmationUrl)
            ->assertOk()
            ->assertSee('Approve submission')
            ->assertSee($this->submission->title);

        $this->assertSame(0, WorkflowAction::query()->count());

        $this->post($approvalUrl)
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'The submission was approved successfully.');

        $this->assertSame(SubmissionStatus::Approved, $this->submission->fresh()->status);
        $this->assertDatabaseHas('workflow_actions', [
            'instance_id' => $instance->id,
            'step_id' => $instance->currentStep->id,
            'actor_id' => $this->approver->id,
            'action' => 'approve',
            'comment' => 'Approved through a secure email link.',
        ]);
    }

    public function test_tampered_signature_is_rejected_and_does_not_mutate_workflow(): void
    {
        $instance = $this->submission->workflowInstance()->with('currentStep')->firstOrFail();
        $url = EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $this->approver);
        $tampered = (string) preg_replace('/([?&])signature=[^&]+/', '$1signature=deadbeef', $url);

        $this->get($tampered)->assertForbidden();
        $this->assertSame(0, WorkflowAction::query()->count());
        $this->assertSame(SubmissionStatus::InReview, $this->submission->fresh()->status);
    }

    public function test_expired_or_stale_email_approval_links_are_rejected(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');
        $instance = $this->submission->workflowInstance()->with('currentStep')->firstOrFail();
        $expiredUrl = EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $this->approver, now()->addHour());

        Carbon::setTestNow('2026-08-17 13:01:00');
        $this->get($expiredUrl)->assertForbidden();

        Carbon::setTestNow('2026-08-17 12:00:00');
        $validUrl = EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $this->approver, now()->addHour());
        app(WorkflowEngine::class)->processAction($instance, $this->approver, WorkflowActionType::Approve);

        $this->get($validUrl)->assertGone();
    }

    public function test_signed_link_cannot_authorize_an_unassigned_user(): void
    {
        $stranger = User::factory()->create()->assignRole('approver');
        $instance = $this->submission->workflowInstance()->with('currentStep')->firstOrFail();
        $url = EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $stranger);

        $this->get($url)->assertForbidden();
    }

    public function test_admin_selects_link_lifetime_and_notification_uses_it(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Carbon::setTestNow('2026-08-17 12:00:00');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('emailLinkExpirationHours', 24)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('24', Setting::get('approvals.email_link_expiration_hours'));

        $mail = (new ActionRequiredNotification($this->submission))->toMail($this->approver);
        parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);

        $this->assertSame(now()->addHours(24)->timestamp, (int) $query['expires']);
    }
}
