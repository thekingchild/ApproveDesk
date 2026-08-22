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

    public function test_builder_surfaces_draft_warnings_before_publish(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $component = Livewire::actingAs($admin)->test('pages::admin.request-types')
            ->call('createType')
            ->set('name', 'Warning Form')
            ->set('fields.0.label', 'Leave category')
            ->set('fields.0.type', 'select')
            ->set('fields.0.options', '')
            ->call('addField')
            ->set('fields.1.label', 'Start date')
            ->set('fields.1.type', 'date')
            ->set('fields.1.min', '2026-12-31')
            ->set('fields.1.max', '2026-01-01');

        // The advisory warnings panel lists both problems before any save.
        $this->assertNotEmpty($component->instance()->draftWarnings);
        $component->assertSee('Draft checks', false)
            ->assertSee('at least one option', false)
            ->assertSee('earliest date is after the latest date', false);

        // Server-side schema validation still guards saving (authority).
        $component->call('save', false)->assertHasErrors();

        // Fixing the fields clears the warnings and the save goes through.
        $component->set('fields.0.options', "Annual\nSick")
            ->set('fields.1.min', '2026-01-01')
            ->set('fields.1.max', '2026-12-31')
            ->call('save', false)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('request_types', ['slug' => 'warning-form']);
    }

    public function test_live_preview_honors_visibility_and_required_rules(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $type = RequestType::factory()->create(['name' => 'Preview Rules']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'category', 'label' => 'Category', 'type' => RequestFieldType::Select,
            'configuration' => ['options' => ['Travel', 'Equipment']],
        ]);
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'destination', 'label' => 'Destination', 'type' => RequestFieldType::ShortText, 'position' => 1,
            'configuration' => [
                'visibility' => ['field' => 'category', 'operator' => 'equals', 'value' => 'Travel'],
                'required_when' => ['field' => 'category', 'operator' => 'equals', 'value' => 'Travel'],
            ],
        ]);

        $component = Livewire::actingAs($admin)->test('pages::admin.request-types')
            ->call('editType', $type->id);

        $this->assertFalse($component->instance()->previewVisible(1));
        $this->assertFalse($component->instance()->previewRequired(1));

        $component->set('previewValues.category', 'Equipment');
        $this->assertFalse($component->instance()->previewVisible(1));

        $component->set('previewValues.category', 'Travel');
        $this->assertTrue($component->instance()->previewVisible(1));
        $this->assertTrue($component->instance()->previewRequired(1));
    }

    public function test_condition_value_pickers_offer_source_field_options(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $type = RequestType::factory()->create(['name' => 'Picker Options']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'category', 'label' => 'Category', 'type' => RequestFieldType::Select,
            'configuration' => ['options' => ['Travel', 'Equipment']],
        ]);
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'destination', 'label' => 'Destination', 'type' => RequestFieldType::ShortText, 'position' => 1,
            'configuration' => ['visibility' => ['field' => 'category', 'operator' => 'equals', 'value' => 'Travel']],
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($version);

        Livewire::actingAs($admin)->test('pages::admin.request-types')
            ->call('editType', $type->id)
            ->assertSee('Travel</option>', false)
            ->assertSee('Equipment</option>', false)
            // Free-text value for the non-choice source stays available in the builder state.
            ->set('fields.1.visibilityValue', 'Travel');
    }

    public function test_local_draft_restore_and_discard_are_scoped_to_the_editing_session(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $type = RequestType::factory()->create(['name' => 'Local Draft']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'reason', 'label' => 'Reason', 'type' => RequestFieldType::LongText, 'is_required' => true,
        ]);

        $snapshot = [
            'editingTypeId' => $type->id,
            'name' => 'Local Draft Renamed',
            'description' => '',
            'workflowTemplateId' => '',
            'fields' => [[
                'label' => 'Reason', 'key' => 'reason', 'keyAuto' => false, 'type' => 'long_text', 'is_required' => true,
                'is_searchable' => false, 'is_filterable' => false, 'is_reportable' => false,
                'is_sensitive' => false, 'is_approver_summary' => false,
                'section' => '', 'instructions' => '', 'placeholder' => '', 'help' => '', 'default' => '',
                'maxLength' => '', 'min' => '', 'max' => '', 'options' => '', 'optionsCsv' => '', 'allowedTypes' => '',
                'visibilityField' => '', 'visibilityOperator' => 'equals', 'visibilityValue' => '',
                'requiredField' => '', 'requiredOperator' => 'equals', 'requiredValue' => '', 'calculationFields' => '',
            ]],
            'selectionRules' => [],
        ];

        $component = Livewire::actingAs($admin)->test('pages::admin.request-types')
            ->call('editType', $type->id);

        // A matching snapshot restores the unsaved browser edits.
        $component->call('restoreLocalDraft', $snapshot)
            ->assertSet('name', 'Local Draft Renamed')
            ->assertSet('localDraftAvailable', false);

        // A snapshot from a different type is rejected.
        Livewire::actingAs($admin)->test('pages::admin.request-types')
            ->call('editType', $type->id)
            ->set('name', 'Fresh Edit')
            ->call('restoreLocalDraft', ['editingTypeId' => $type->id + 999, 'fields' => []])
            ->assertSet('name', 'Fresh Edit');

        // Discard blocks later restores in the same session.
        Livewire::actingAs($admin)->test('pages::admin.request-types')
            ->call('editType', $type->id)
            ->call('discardLocalDraft')
            ->call('restoreLocalDraft', $snapshot)
            ->assertSet('name', 'Local Draft')
            ->assertSet('localDraftDiscarded', true);
    }

    public function test_date_bounds_use_calendar_pickers_and_warn_on_number_limits(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $component = Livewire::actingAs($admin)->test('pages::admin.request-types')
            ->call('createType')
            ->set('name', 'Calendar Bounds')
            ->set('fields.0.label', 'Start date')
            ->set('fields.0.type', 'date')
            // The min=1/max=2 trap typed into what used to be number boxes.
            ->set('fields.0.min', '1')
            ->set('fields.0.max', '2')
            ->call('addField')
            ->set('fields.1.label', 'Amount')
            ->set('fields.1.type', 'currency')
            ->set('fields.1.min', '1000');

        // Date fields get date pickers with plain-language labels.
        $component->assertSee('type="date"', false)
            ->assertSee('Earliest date requesters can choose', false)
            ->assertSee('Latest date requesters can choose', false)
            // Numeric fields keep numeric bounds with value labels.
            ->assertSee('Minimum value', false)
            ->assertSee('Maximum value', false);

        $this->assertNotEmpty($component->instance()->draftWarnings);
        $component->assertSee('is not a real date', false);

        // Fixing the bounds clears the warning and echoes the window back.
        $component->set('fields.0.min', '2026-09-01')
            ->set('fields.0.max', '2026-12-31')
            ->assertSee('from', false)
            ->assertSee('1 Sep 2026', false)
            ->assertSee('up to', false)
            ->assertSee('31 Dec 2026', false);

        $this->assertTrue(collect($component->instance()->draftWarnings)
            ->every(fn (array $warning): bool => ! str_contains((string) $warning['message'], 'not a real date')));

        // Publishing a sane window succeeds end to end.
        $component->call('save', true)->assertHasNoErrors();

        $configuration = RequestType::query()->where('slug', 'calendar-bounds')->firstOrFail()
            ->publishedVersion
            ->fields()
            ->where('key', 'start_date')
            ->sole()
            ->configuration;

        $this->assertSame('2026-09-01', $configuration['min']);
        $this->assertSame('2026-12-31', $configuration['max']);
    }
}
