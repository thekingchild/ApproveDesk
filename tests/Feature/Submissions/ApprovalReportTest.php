<?php

namespace Tests\Feature\Submissions;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\StoreSubmissionFieldValuesAction;
use App\Enums\RequestFieldType;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\SensitiveFieldAccess;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Models\WorkflowTemplateVersion;
use App\Services\ApprovalReportService;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ApprovalReportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $participant;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->owner = User::factory()->create()->assignRole('submitter');
        $this->participant = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->participant])->create();
        $this->submission = Submission::factory()->for($this->owner, 'submitter')->create([
            'title' => 'Quarterly equipment approval',
            'reference_number' => 'REQ-2026-0042',
        ]);
    }

    public function test_stranger_is_denied_both_report_routes(): void
    {
        $stranger = User::factory()->create()->assignRole('viewer');

        $this->actingAs($stranger)
            ->get("/submissions/{$this->submission->id}/approval-report")
            ->assertForbidden();
        $this->actingAs($stranger)
            ->get("/submissions/{$this->submission->id}/approval-report.pdf")
            ->assertForbidden();
    }

    public function test_report_embeds_configured_branding_logo_bytes_as_a_data_uri(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/report-logo.png', 'local-logo-bytes');
        Setting::put('brand.logo', 'branding/report-logo.png');

        $this->actingAs($this->owner)
            ->get("/submissions/{$this->submission->id}/approval-report")
            ->assertOk()
            ->assertSee('data:image/png;base64,'.base64_encode('local-logo-bytes'), false)
            ->assertDontSee('/branding/assets/report-logo.png', false);
    }

    public function test_report_embeds_configured_favicon_as_a_data_uri(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/report-favicon.png', 'local-favicon-bytes');
        Setting::put('brand.favicon', 'branding/report-favicon.png');

        $faviconUri = 'data:image/png;base64,'.base64_encode('local-favicon-bytes');

        $this->actingAs($this->owner)
            ->get("/submissions/{$this->submission->id}/approval-report")
            ->assertOk()
            ->assertSee('<link rel="icon" href="'.$faviconUri.'">', false)
            ->assertSee('<img class="brand-logo" src="'.$faviconUri.'"', false)
            ->assertDontSee('/branding/assets/report-favicon.png', false);
    }

    public function test_report_always_prints_wrapped_company_name_beside_the_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('branding/report-logo.png', 'local-logo-bytes');
        Setting::put('brand.logo', 'branding/report-logo.png');
        Setting::put('brand.meta_title', 'Bluechip Analytical Services and Integrated Laboratory Operations Limited');

        $this->actingAs($this->owner)
            ->get("/submissions/{$this->submission->id}/approval-report")
            ->assertOk()
            ->assertSee('data-test="report-company-identity"', false)
            ->assertSee('Bluechip Analytical Services and Integrated Laboratory Operations Limited')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('brand-identity', false);
    }

    public function test_report_sanitizes_the_request_body_again_at_render_time(): void
    {
        DB::table('submissions')->where('id', $this->submission->id)->update([
            'body' => '<p>Approved body copy.</p><script>window.reportLeak = true</script>',
        ]);

        $this->actingAs($this->owner)
            ->get("/submissions/{$this->submission->id}/approval-report")
            ->assertOk()
            ->assertSee('Approved body copy.')
            ->assertDontSee('window.reportLeak');
    }

    public function test_report_renders_sanitized_request_content_visible_typed_fields_and_attachment_manifest(): void
    {
        $type = RequestType::factory()->create(['name' => 'Capital purchase']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'budget_code_internal',
            'label' => 'Budget code',
            'type' => RequestFieldType::ShortText,
            'position' => 1,
        ]);
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'confidential_amount_key',
            'label' => 'Confidential amount',
            'type' => RequestFieldType::Currency,
            'is_sensitive' => true,
            'position' => 2,
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($version);

        $submission = Submission::factory()->for($this->owner, 'submitter')->create([
            'request_type_version_id' => $version->id,
            'body' => '<p>Replace the field laptops.</p><script>alert(1)</script>',
        ]);
        app(StoreSubmissionFieldValuesAction::class)->execute($submission, [
            'budget_code_internal' => 'OPS-2026',
            'confidential_amount_key' => '12500.50',
        ]);
        SubmissionFile::factory()->for($submission)->for($this->owner, 'uploader')->create([
            'original_name' => 'supplier-quote.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
        ]);
        app(WorkflowEngine::class)->initialize($submission);

        $this->actingAs($this->participant)
            ->get("/submissions/{$submission->id}/approval-report")
            ->assertOk()
            ->assertSee('Capital purchase')
            ->assertSee('Replace the field laptops.')
            ->assertDontSee('alert(1)')
            ->assertSee('Budget code')
            ->assertSee('OPS-2026')
            ->assertDontSee('Confidential amount')
            ->assertDontSee('12500.500000')
            ->assertDontSee('budget_code_internal')
            ->assertDontSee('confidential_amount_key')
            ->assertSee('supplier-quote.pdf')
            ->assertSee('application/pdf')
            ->assertSee('2 KB');
    }

    public function test_owner_and_workflow_participant_can_preview_the_approval_report(): void
    {
        app(WorkflowEngine::class)->initialize($this->submission);

        foreach ([$this->owner, $this->participant] as $viewer) {
            $this->actingAs($viewer)
                ->get("/submissions/{$this->submission->id}/approval-report")
                ->assertOk()
                ->assertSee('Approval report')
                ->assertSee('Quarterly equipment approval')
                ->assertSee('REQ-2026-0042');
        }
    }

    public function test_report_embeds_the_supplied_image_for_every_current_status(): void
    {
        $filenames = [
            SubmissionStatus::Draft->value => 'draft.png',
            SubmissionStatus::Pending->value => 'pending.png',
            SubmissionStatus::InReview->value => 'in-review.png',
            SubmissionStatus::Approved->value => 'approved.png',
            SubmissionStatus::Rejected->value => 'rejected.png',
            SubmissionStatus::Returned->value => 'returned.png',
            SubmissionStatus::AwaitingInformation->value => 'awaiting-information.png',
            SubmissionStatus::Withdrawn->value => 'withdrawn.png',
        ];

        foreach (SubmissionStatus::cases() as $status) {
            $this->submission->update(['status' => $status]);
            $report = app(ApprovalReportService::class)->build($this->submission->fresh(), $this->owner);
            $path = public_path('images/approval-status-stamps/'.$filenames[$status->value]);

            $this->assertFileExists($path);
            $expected = 'data:image/png;base64,'.base64_encode((string) file_get_contents($path));
            $this->assertSame($status->value, $report['status_stamp_key']);
            $this->assertSame($expected, $report['status_stamp_data_uri']);
        }

        $this->submission->update(['status' => SubmissionStatus::Approved]);
        $this->actingAs($this->owner)
            ->get(route('submissions.approval-report.show', $this->submission))
            ->assertOk()
            ->assertSee('data-test="approval-status-stamp"', false)
            ->assertSee('data-status-stamp="approved"', false)
            ->assertSee('aria-label="Approval status: Approved"', false)
            ->assertSee('>Status</th>', false)
            ->assertSee('>Approved</td>', false)
            ->assertSee('data:image/png;base64,', false)
            ->assertSee('max-height: 112px', false)
            ->assertSee('max-width: 112px', false)
            ->assertDontSee('border: 3px double currentColor', false)
            ->assertDontSee('transform: rotate(-2deg)', false);
    }

    public function test_report_lists_workflow_steps_and_append_only_actions_in_the_installation_timezone(): void
    {
        config()->set('app.timezone', 'Africa/Lagos');
        app(WorkflowEngine::class)->initialize($this->submission);
        $instance = $this->submission->fresh()->workflowInstance;
        $step = $instance->currentStep;

        $approval = WorkflowAction::factory()->create([
            'instance_id' => $instance->id,
            'step_id' => $step->id,
            'actor_id' => $this->participant->id,
            'action' => WorkflowActionType::ApproveWithConditions,
            'comment' => 'Approved after confirming the budget ceiling.',
            'created_at' => Carbon::parse('2026-08-28 10:30:00', 'UTC'),
        ]);
        WorkflowAction::factory()->create([
            'instance_id' => $instance->id,
            'step_id' => $step->id,
            'actor_id' => null,
            'action' => WorkflowActionType::AutoApprove,
            'created_at' => Carbon::parse('2026-08-28 10:35:00', 'UTC'),
        ]);

        $this->actingAs($this->owner)
            ->get(route('submissions.approval-report.show', $this->submission))
            ->assertOk()
            ->assertSee($step->name)
            ->assertSee($this->participant->name)
            ->assertSee('Approve with conditions')
            ->assertSee('Approved after confirming the budget ceiling.')
            ->assertSee($approval->fresh()->created_at->copy()->timezone('Africa/Lagos')->format('d M Y H:i'))
            ->assertSee('System')
            ->assertSee('Auto approve')
            ->assertSee('Africa/Lagos');
    }

    public function test_pdf_download_returns_a_real_pdf_with_a_reference_based_filename(): void
    {
        app(WorkflowEngine::class)->initialize($this->submission);

        $response = $this->actingAs($this->owner)
            ->get(route('submissions.approval-report.download', $this->submission));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename="REQ-2026-0042-approval-report.pdf"');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_report_keeps_the_workflow_steps_from_the_instance_original_version(): void
    {
        app(WorkflowEngine::class)->initialize($this->submission);
        $instance = $this->submission->fresh()->workflowInstance;
        $originalVersion = $instance->templateVersion;
        $originalVersion->updateQuietly(['status' => WorkflowTemplateVersionStatus::Retired]);
        $newVersion = WorkflowTemplateVersion::query()->create([
            'workflow_template_id' => $instance->template_id,
            'version' => 2,
            'status' => WorkflowTemplateVersionStatus::Draft,
            'change_summary' => 'Future workflow version.',
            'created_by' => $this->owner->id,
        ]);
        WorkflowTemplateStep::factory()->forVersion($newVersion, 1)->create([
            'name' => 'Future replacement step',
            'assignee_type' => 'user',
            'assignee_ids' => [$this->participant->id],
        ]);
        $newVersion->updateQuietly(['status' => WorkflowTemplateVersionStatus::Published]);

        $this->actingAs($this->owner)
            ->get(route('submissions.approval-report.show', $this->submission))
            ->assertOk()
            ->assertSee('Approval Level 1')
            ->assertDontSee('Future replacement step');
    }

    public function test_report_uses_the_recorded_workflow_versions_custom_outcome_label(): void
    {
        app(WorkflowEngine::class)->initialize($this->submission);
        $instance = $this->submission->fresh()->workflowInstance;
        $instance->templateVersion->updateQuietly([
            'outcomes' => [[
                'key' => 'executive_signoff',
                'label' => 'Executive sign-off completed',
                'behavior' => 'approve',
                'requires_comment' => true,
            ]],
        ]);
        WorkflowAction::factory()->create([
            'instance_id' => $instance->id,
            'step_id' => $instance->current_step_id,
            'actor_id' => $this->participant->id,
            'action' => WorkflowActionType::Approve,
            'outcome_key' => 'executive_signoff',
        ]);

        $this->actingAs($this->owner)
            ->get(route('submissions.approval-report.show', $this->submission))
            ->assertOk()
            ->assertSee('Executive sign-off completed')
            ->assertDontSee('Executive signoff');
    }

    public function test_submission_page_offers_report_preview_and_pdf_for_system_and_custom_requests(): void
    {
        $customType = RequestType::factory()->create(['name' => 'Travel request']);
        $customVersion = RequestTypeVersion::factory()->for($customType)->create();
        RequestFieldDefinition::factory()->for($customVersion)->create([
            'key' => 'destination',
            'label' => 'Destination',
            'type' => RequestFieldType::ShortText,
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($customVersion);
        $customSubmission = Submission::factory()->for($this->owner, 'submitter')->create([
            'request_type_version_id' => $customVersion->id,
        ]);

        foreach ([$this->submission, $customSubmission] as $submission) {
            $this->actingAs($this->owner)
                ->get(route('submissions.show', $submission))
                ->assertOk()
                ->assertSee('Approval report')
                ->assertSee(route('submissions.approval-report.show', $submission), false)
                ->assertSee('Download PDF')
                ->assertSee(route('submissions.approval-report.download', $submission), false);
        }
    }

    public function test_system_request_without_a_workflow_renders_a_complete_empty_state(): void
    {
        $this->actingAs($this->owner)
            ->get(route('submissions.approval-report.show', $this->submission))
            ->assertOk()
            ->assertSee('General Request')
            ->assertSee('No approval workflow has started.')
            ->assertSee('No workflow actions recorded yet.')
            ->assertSee('No attachments recorded.')
            ->assertDontSee('Custom fields')
            ->assertDontSee('Request category');
    }

    public function test_preview_has_document_only_a4_print_rules_and_a_repeating_footer(): void
    {
        $this->actingAs($this->owner)
            ->get(route('submissions.approval-report.show', $this->submission))
            ->assertOk()
            ->assertSee('@page { size: A4 portrait;', false)
            ->assertSee('display: table-header-group', false)
            ->assertSee('.pdf .running-footer { display: block; position: fixed;', false)
            ->assertSee('counter(page)', false)
            ->assertDontSee('data-flux-sidebar', false);
    }

    public function test_authorized_sensitive_field_is_included_and_its_report_access_is_recorded(): void
    {
        $type = RequestType::factory()->create(['name' => 'Medical request']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        $field = RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'medical_detail',
            'label' => 'Medical detail',
            'type' => RequestFieldType::ShortText,
            'is_sensitive' => true,
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($version);
        $submission = Submission::factory()->for($this->owner, 'submitter')->create([
            'request_type_version_id' => $version->id,
        ]);
        app(StoreSubmissionFieldValuesAction::class)->execute($submission, [
            'medical_detail' => 'Restricted report value',
        ]);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('submissions.approval-report.show', $submission))
            ->assertOk()
            ->assertSee('Medical detail')
            ->assertSee('Restricted report value');

        $this->assertDatabaseHas('sensitive_field_accesses', [
            'user_id' => $admin->id,
            'submission_id' => $submission->id,
            'request_field_definition_id' => $field->id,
            'context' => 'approval_report',
        ]);
        $this->assertSame(1, SensitiveFieldAccess::query()->count());
    }
}
