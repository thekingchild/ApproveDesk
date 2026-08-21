<?php

namespace Tests\Feature\Settings;

use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\ActionRequiredNotification;
use App\Services\WorkflowEngine;
use App\Support\EmailApprovalLinks;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmailTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_custom_template_changes_mail_copy_and_restore_returns_default(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();

        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->set('tab', 'email')
            ->set('templateKind', 'action_required')
            ->set('templateSubject', 'Act on {{reference_number}}')
            ->set('templateBody', 'Please review {{title}} at {{approval_url}}')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $mail = (new ActionRequiredNotification($submission))->toMail($approver);
        $this->assertSame('Act on '.$submission->reference_number, $mail->subject);
        $this->assertStringContainsString($submission->title, implode("\n", $mail->introLines));

        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->set('templateKind', 'action_required')
            ->call('restoreTemplate');

        $restored = (new ActionRequiredNotification($submission))->toMail($approver);
        $this->assertSame(
            '['.$submission->reference_number.'] Action required: '.$submission->title,
            $restored->subject,
        );
    }

    public function test_action_required_template_without_approval_url_is_rejected(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->set('templateKind', 'action_required')
            ->set('templateSubject', 'Act')
            ->set('templateBody', 'No link here')
            ->call('saveTemplate')
            ->assertHasErrors('templateBody');
    }

    public function test_rendered_action_required_mail_still_has_a_signed_confirmation_url(): void
    {
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();
        app(WorkflowEngine::class)->initialize($submission);

        $mail = (new ActionRequiredNotification($submission->fresh()))->toMail($approver);
        $this->assertNotNull($mail->actionUrl);
        $this->assertStringContainsString('/email-approvals/', (string) $mail->actionUrl);
        parse_str((string) parse_url((string) $mail->actionUrl, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('signature', $query);
        $this->assertArrayHasKey('expires', $query);
        $this->assertGreaterThan(now()->timestamp, (int) $query['expires']);
        $this->assertSame(EmailApprovalLinks::expirationHours() * 3600, (int) $query['expires'] - now()->timestamp);
    }
}
