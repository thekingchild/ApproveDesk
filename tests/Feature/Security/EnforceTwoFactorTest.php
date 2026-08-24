<?php

namespace Tests\Feature\Security;

use App\Actions\Security\UpdateSecuritySettingsAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Support\EmailApprovalLinks;
use App\Support\SecuritySettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class EnforceTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_unenrolled_user_reaches_dashboard_when_requirement_is_off(): void
    {
        $user = User::factory()->create()->assignRole('submitter');

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_unenrolled_user_is_sent_to_security_when_requirement_is_on(): void
    {
        Setting::put('auth.two_factor_required', '1');
        $user = User::factory()->create()->assignRole('submitter');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('security.edit'));

        $this->actingAs($user)
            ->get(route('approvals.inbox'))
            ->assertRedirect(route('security.edit'));

        $this->actingAs($user)
            ->get(route('admin.updates.index'))
            ->assertRedirect(route('security.edit'));
    }

    public function test_confirmed_two_factor_user_reaches_dashboard_when_requirement_is_on(): void
    {
        Setting::put('auth.two_factor_required', '1');
        $user = User::factory()->withTwoFactor()->create()->assignRole('submitter');

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_passkey_only_user_satisfies_the_requirement(): void
    {
        Setting::put('auth.two_factor_required', '1');
        $user = User::factory()->create()->assignRole('submitter');
        $user->passkeys()->create([
            'name' => 'Laptop',
            'credential_id' => 'cred-passkey-1',
            'credential' => ['id' => 'cred-passkey-1'],
        ]);

        $this->assertTrue($user->hasSatisfiedSecondFactor());
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_administrator_cannot_enable_requirement_without_being_enrolled(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.security-privacy')
            ->set('twoFactorRequired', true)
            ->call('save')
            ->assertHasErrors('twoFactorRequired');

        $this->assertFalse(SecuritySettings::twoFactorRequired());
    }

    public function test_enrolled_administrator_can_enable_and_disable_the_requirement(): void
    {
        $admin = User::factory()->withTwoFactor()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.security-privacy')
            ->set('twoFactorRequired', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(SecuritySettings::twoFactorRequired());

        Livewire::actingAs($admin)
            ->test('pages::settings.security-privacy')
            ->set('twoFactorRequired', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(SecuritySettings::twoFactorRequired());
    }

    public function test_submitter_cannot_change_the_organization_setting(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('settings.security-privacy'))
            ->assertForbidden();
    }

    public function test_signed_email_approval_still_works_when_requirement_is_on(): void
    {
        Setting::put('auth.two_factor_required', '1');
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);
        app(SubmitSubmissionAction::class)->execute($submission);
        $instance = $submission->workflowInstance()->with('currentStep')->firstOrFail();
        $url = EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $approver);

        $this->get($url)->assertOk()->assertSee($submission->title);
    }

    public function test_existing_api_token_still_authenticates_when_requirement_is_on(): void
    {
        Setting::put('auth.two_factor_required', '1');
        $user = User::factory()->create()->assignRole('submitter');
        Sanctum::actingAs($user, ['submissions:read']);

        $this->getJson('/api/v1/submissions')->assertOk();
    }

    public function test_creating_a_token_fails_when_required_and_unenrolled(): void
    {
        Setting::put('auth.two_factor_required', '1');
        $user = User::factory()->create()->assignRole('submitter');

        Livewire::actingAs($user)
            ->test('pages::settings.api-tokens')
            ->set('tokenName', 'Finance export')
            ->set('abilities', ['submissions:read'])
            ->call('create')
            ->assertHasErrors('tokenName');

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_action_rejects_enabling_without_an_enrolled_actor(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(UpdateSecuritySettingsAction::class)->execute(
            ['two_factor_required' => true],
            User::factory()->create(),
        );
    }
}
