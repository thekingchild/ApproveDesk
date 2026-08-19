<?php

namespace Tests\Feature\RequestTypes;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Enums\RequestFieldType;
use App\Enums\RequestTypeVersionStatus;
use App\Enums\SubmissionStatus;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RequestTypeBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_only_administrators_can_open_the_request_type_builder(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($admin)->get(route('admin.request-types.index'))->assertOk();
        $this->actingAs($submitter)->get(route('admin.request-types.index'))->assertForbidden();
    }

    public function test_admin_can_build_publish_clone_and_archive_a_request_type(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $field = [
            'label' => 'Leave reason', 'key' => 'leave_reason', 'type' => 'long_text', 'is_required' => true,
            'section' => 'Leave details', 'instructions' => 'Explain the request.', 'placeholder' => 'Reason', 'help' => 'Be specific.',
            'default' => '', 'maxLength' => '1000', 'min' => '', 'max' => '', 'options' => '', 'optionsCsv' => '', 'allowedTypes' => '',
            'visibilityField' => '', 'visibilityOperator' => 'equals', 'visibilityValue' => '',
            'requiredField' => '', 'requiredOperator' => 'equals', 'requiredValue' => '', 'calculationFields' => '',
        ];

        Livewire::actingAs($admin)->test('pages::admin.request-types')
            ->call('createType')
            ->set('name', 'Leave Request')
            ->set('description', 'Request time away')
            ->set('fields', [$field])
            ->call('save', true)
            ->assertHasNoErrors();

        $type = RequestType::query()->where('slug', 'leave-request')->firstOrFail();
        $this->assertSame(RequestTypeVersionStatus::Published, $type->publishedVersion->status);
        $this->assertSame('Leave details', $type->publishedVersion->fields()->first()->configuration['section']);

        Livewire::actingAs($admin)->test('pages::admin.request-types')->call('cloneType', $type->id);
        $this->assertDatabaseHas('request_types', ['slug' => 'leave-request-copy', 'is_active' => true]);

        Livewire::actingAs($admin)->test('pages::admin.request-types')->call('archive', $type->id);
        $this->assertFalse($type->fresh()->is_active);
    }

    public function test_builder_hides_keys_and_generates_them_from_labels(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $field = [
            'label' => 'Leave reason', 'key' => '', 'keyAuto' => true, 'type' => 'long_text', 'is_required' => true,
            'section' => '', 'instructions' => '', 'placeholder' => '', 'help' => '',
            'default' => '', 'maxLength' => '', 'min' => '', 'max' => '', 'options' => '', 'optionsCsv' => '', 'allowedTypes' => '',
            'visibilityField' => '', 'visibilityOperator' => 'equals', 'visibilityValue' => '',
            'requiredField' => '', 'requiredOperator' => 'equals', 'requiredValue' => '', 'calculationFields' => '',
        ];

        Livewire::actingAs($admin)
            ->test('pages::admin.request-types')
            ->call('createType')
            ->assertDontSee('dusk="field-key-0"', false)
            ->set('name', 'Auto Key Form')
            ->set('fields', [$field, [...$field, 'label' => 'Leave reason']])
            ->call('save', true)
            ->assertHasNoErrors();

        $keys = RequestType::query()->where('slug', 'auto-key-form')->firstOrFail()
            ->publishedVersion
            ->fields()
            ->orderBy('position')
            ->pluck('key')
            ->all();

        $this->assertSame(['leave_reason', 'leave_reason_2'], $keys);
    }

    public function test_existing_field_keeps_its_key_when_the_label_changes(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $type = RequestType::factory()->create(['name' => 'Stable Keys']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'leave_reason',
            'label' => 'Leave reason',
            'type' => RequestFieldType::LongText,
        ]);

        Livewire::actingAs($admin)
            ->test('pages::admin.request-types')
            ->call('editType', $type->id)
            ->set('fields.0.label', 'Reason for absence')
            ->call('save', false)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('request_field_definitions', [
            'request_type_version_id' => $type->versions()->where('status', RequestTypeVersionStatus::Draft)->value('id'),
            'key' => 'leave_reason',
            'label' => 'Reason for absence',
        ]);
    }

    public function test_requester_can_save_reopen_review_and_submit_a_custom_form(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $type = RequestType::factory()->create(['name' => 'Leave Request']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create(['key' => 'reason', 'label' => 'Reason', 'type' => RequestFieldType::LongText, 'is_required' => true]);
        RequestFieldDefinition::factory()->for($version)->create(['key' => 'start_date', 'label' => 'Start date', 'type' => RequestFieldType::Date, 'position' => 1, 'is_required' => true]);
        app(PublishRequestTypeVersionAction::class)->execute($version);

        Livewire::actingAs($submitter)->test('pages::submissions.create-custom', ['requestType' => $type])
            ->set('values.reason', 'Family holiday')
            ->set('values.start_date', '2026-09-01')
            ->call('saveDraft')
            ->assertHasNoErrors();

        $draft = Submission::query()->where('submitter_id', $submitter->id)->sole();
        $this->assertSame(SubmissionStatus::Draft, $draft->status);
        $this->assertSame($version->id, $draft->request_type_version_id);

        Livewire::actingAs($submitter)->test('pages::submissions.create-custom', ['submission' => $draft])
            ->assertSet('values.reason', 'Family holiday')
            ->call('review')
            ->assertSet('step', 2)
            ->call('submit')
            ->assertHasNoErrors();

        $this->assertSame(SubmissionStatus::InReview, $draft->fresh()->status);
        $this->assertSame(2, $draft->fieldValues()->count());
    }
}
