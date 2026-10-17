<?php

namespace Tests\Feature\Approvals;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Services\Auth\SecondFactorAssurance;
use App\Support\EmailApprovalLinks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailApprovalSecondFactorTest extends TestCase
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

    public function test_guest_flow_remains_unchanged_when_enforcement_is_off(): void
    {
        $this->get($this->confirmationUrl())
            ->assertOk()
            ->assertSee($this->submission->title);
    }

    public function test_valid_link_requires_login_and_preserves_intended_url_when_enforcement_is_on(): void
    {
        $this->enableEnforcement();
        $url = $this->confirmationUrl();

        $this->get($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $url)
            ->assertSessionHas('approval_second_factor_required');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('This approval link requires the named approver to sign in and verify a second factor.');
    }

    public function test_tampered_link_is_rejected_before_login_redirect(): void
    {
        $this->enableEnforcement();
        $tampered = (string) preg_replace(
            '/([?&])signature=[^&]+/',
            '$1signature=deadbeef',
            $this->confirmationUrl(),
        );

        $this->get($tampered)
            ->assertForbidden()
            ->assertSessionMissing('url.intended');
    }

    public function test_wrong_authenticated_account_is_denied_without_request_details(): void
    {
        $this->enableEnforcement();
        $other = User::factory()->withTwoFactor()->create()->assignRole('approver');

        $this->actingAs($other)
            ->withSession($this->assuredSession($other))
            ->get($this->confirmationUrl())
            ->assertForbidden()
            ->assertDontSee($this->submission->title);
    }

    public function test_approver_without_an_enrolled_factor_is_sent_to_security_settings(): void
    {
        $this->enableEnforcement();
        $url = $this->confirmationUrl();

        $this->actingAs($this->approver)
            ->get($url)
            ->assertRedirect(route('security.edit'))
            ->assertSessionHas('url.intended', $url)
            ->assertSessionHas('approval_second_factor_enrollment_required');

        $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertSee('Set up an authenticator or passkey to continue to this approval.')
            ->assertSee('Return to approval after setup')
            ->assertSee((string) parse_url($url, PHP_URL_PATH), escape: false);
    }

    public function test_unassured_totp_only_approver_must_sign_in_again(): void
    {
        $this->enableEnforcement();
        $this->approver->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $url = $this->confirmationUrl();

        $this->actingAs($this->approver)
            ->get($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $url)
            ->assertSessionHas('approval_second_factor_required');

        $this->assertGuest();
    }

    public function test_unassured_passkey_approver_can_sign_in_again_with_the_passkey(): void
    {
        $this->enableEnforcement();
        $this->approver->passkeys()->create([
            'name' => 'Security key',
            'credential_id' => 'approval-passkey',
            'credential' => ['id' => 'approval-passkey'],
        ]);
        $url = $this->confirmationUrl();

        $this->actingAs($this->approver)
            ->get($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $url)
            ->assertSessionHas('approval_second_factor_required');

        $this->assertGuest();
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in with a passkey');
    }

    public function test_assured_named_approver_can_view_and_submit_the_signed_action(): void
    {
        $this->enableEnforcement();
        $this->approver->forceFill([
            'two_factor_secret' => encrypt('test-secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $instance = $this->submission->workflowInstance()->with('currentStep')->firstOrFail();
        $expiresAt = now()->addHour();
        $confirmationUrl = EmailApprovalLinks::confirmationUrl(
            $instance,
            $instance->currentStep,
            $this->approver,
            $expiresAt,
        );
        $approvalUrl = EmailApprovalLinks::approvalUrl(
            $instance,
            $instance->currentStep,
            $this->approver,
            $expiresAt,
        );

        $assuredSession = $this->assuredSession($this->approver) + [
            'approval_second_factor_enrollment_required' => true,
            'approval_second_factor_intended' => $confirmationUrl,
        ];

        $this->actingAs($this->approver)
            ->withSession($assuredSession)
            ->get($confirmationUrl)
            ->assertOk()
            ->assertSee($this->submission->title)
            ->assertSessionMissing('approval_second_factor_enrollment_required')
            ->assertSessionMissing('approval_second_factor_intended');

        $this->actingAs($this->approver)
            ->withSession($this->assuredSession($this->approver))
            ->post($approvalUrl)
            ->assertRedirect(route('home'));

        $this->assertSame(SubmissionStatus::Approved, $this->submission->fresh()->status);
        $this->assertDatabaseHas('workflow_actions', [
            'instance_id' => $instance->id,
            'actor_id' => $this->approver->id,
            'action' => 'approve',
        ]);
        $this->assertSame(1, WorkflowAction::query()->count());
    }

    public function test_disabling_enforcement_restores_guest_use_of_an_existing_valid_link(): void
    {
        $this->enableEnforcement();
        $url = $this->confirmationUrl();

        $this->get($url)->assertRedirect(route('login'));

        Setting::put('approvals.email_require_second_factor', '0');

        $this->get($url)->assertOk();
    }

    private function enableEnforcement(): void
    {
        Setting::put('approvals.email_require_second_factor', '1');
    }

    private function confirmationUrl(): string
    {
        $instance = $this->submission->workflowInstance()->with('currentStep')->firstOrFail();

        return EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $this->approver);
    }

    /** @return array<string, array<string, int|string>> */
    private function assuredSession(User $user): array
    {
        return [
            SecondFactorAssurance::SESSION_KEY => [
                'user_id' => (string) $user->getAuthIdentifier(),
                'verified_at' => now()->timestamp,
                'method' => 'totp',
            ],
        ];
    }
}
