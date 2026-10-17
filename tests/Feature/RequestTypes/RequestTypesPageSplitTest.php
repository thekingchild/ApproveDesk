<?php

namespace Tests\Feature\RequestTypes;

use App\Enums\RequestFieldType;
use App\Livewire\Forms\RequestFieldsForm;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\RequestTypes\RequestSchemaCompiler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** ADR 0006 split of the Request types page, and the RV-ARC-05 fixes that came with it. */
class RequestTypesPageSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->create()->assignRole('admin');
    }

    public function test_page_renders_the_builder_dialog(): void
    {
        $this->actingAs($this->admin)->get(route('admin.request-types.index'))
            ->assertOk()
            ->assertSee('dusk="create-request-type"', false)
            ->assertSee('dusk="request-type-name"', false)
            ->assertSee('dusk="publish-request-type"', false);
    }

    public function test_rv_arc_05_a_refused_selection_rule_does_not_leave_a_new_type_behind(): void
    {
        $builder = Livewire::actingAs($this->admin)->test('admin.request-types.builder')
            ->call('createType')
            ->set('name', 'Travel claim')
            ->set('fields.0.label', 'Destination')
            ->call('addSelectionRule')
            ->call('save', false)
            ->assertHasErrors(['rules.0.name']);

        $this->assertDatabaseMissing('request_types', ['name' => 'Travel claim']);

        // Retrying after the fix creates the type once, not twice.
        $template = WorkflowTemplate::factory()->sequential([User::factory()->create()])->create();
        $builder->set('selectionRules.0.workflow_template_id', (string) $template->id)
            ->call('save', false)
            ->assertHasNoErrors();

        $this->assertSame(1, RequestType::query()->where('name', 'Travel claim')->count());
    }

    public function test_rv_arc_05_a_refused_selection_rule_does_not_save_the_draft_of_an_existing_type(): void
    {
        $type = $this->typeWith([['key' => 'reason', 'label' => 'Reason', 'type' => RequestFieldType::ShortText]]);
        $versions = $type->versions()->count();

        Livewire::actingAs($this->admin)->test('admin.request-types.builder')
            ->call('editType', $type->id)
            ->set('name', 'Renamed')
            ->call('addSelectionRule')
            ->call('save', false)
            ->assertHasErrors(['rules.0.name']);

        $this->assertSame($type->name, $type->fresh()->name);
        $this->assertSame($versions, $type->versions()->count());
    }

    public function test_rv_arc_05_preview_follows_the_live_form_for_a_multi_select_source(): void
    {
        $type = $this->typeWith([
            ['key' => 'needs', 'label' => 'Needs', 'type' => RequestFieldType::MultiSelect, 'configuration' => ['options' => ['hotel', 'flight']]],
            ['key' => 'hotel_name', 'label' => 'Hotel', 'type' => RequestFieldType::ShortText, 'configuration' => [
                'visibility' => ['field' => 'needs', 'operator' => 'equals', 'value' => 'hotel'],
            ]],
        ]);

        $component = Livewire::actingAs($this->admin)->test('admin.request-types.builder')
            ->call('editType', $type->id)
            ->set('previewValues.needs', ['flight', 'hotel']);

        // The old preview compared the whole list to "hotel" and hid the field.
        $this->assertTrue($component->instance()->previewState[1]['visible']);

        $live = app(RequestSchemaCompiler::class);
        $version = $type->versions()->with('fields')->first();
        $this->assertTrue($live->isVisible($version->fields[1], $live->visibleValues($version, ['needs' => ['flight', 'hotel']])));
    }

    public function test_rv_arc_05_an_in_rule_stores_a_list_and_matches_any_item(): void
    {
        $component = Livewire::actingAs($this->admin)->test('admin.request-types.builder')
            ->call('createType')
            ->set('name', 'Purchase')
            ->set('fields.0.label', 'Category')
            ->set('fields.0.type', 'select')
            ->set('fields.0.options', "Laptop\nPhone\nDesk")
            ->call('addField')
            ->set('fields.1.label', 'Asset tag')
            ->set('fields.1.visibilityField', 'category')
            ->set('fields.1.visibilityOperator', 'in')
            ->set('fields.1.visibilityValue', 'Laptop, Phone')
            ->set('previewValues.category', 'Phone');

        $this->assertTrue($component->instance()->previewState[1]['visible']);

        $component->call('save', false)->assertHasNoErrors();

        $field = RequestFieldDefinition::query()->where('key', 'asset_tag')->firstOrFail();
        $this->assertSame(['Laptop', 'Phone'], $field->configuration['visibility']['value']);
    }

    public function test_rv_arc_05_draft_checks_are_the_problems_save_refuses(): void
    {
        $fields = [
            [...RequestFieldsForm::blank(), 'label' => 'Total', 'key' => 'total', 'type' => 'calculated', 'calculationFields' => 'amount'],
            [...RequestFieldsForm::blank(), 'label' => 'Amount', 'key' => 'amount', 'type' => 'number'],
        ];

        $component = Livewire::actingAs($this->admin)->test('admin.request-types.builder')
            ->call('createType')
            ->set('name', 'Order matters')
            ->set('fields', $fields);

        // A sum of a later field: the old warnings only checked that something was ticked.
        $warnings = $component->instance()->draftWarnings;
        $this->assertSame([0], array_column($warnings, 'index'));
        $this->assertSame('calculation', $warnings[0]['key']);

        $component->call('save', false)->assertHasErrors(['fields.0.calculation']);
        $this->assertDatabaseMissing('request_types', ['name' => 'Order matters']);
    }

    public function test_rv_arc_05_saving_without_changing_the_workflow_needs_no_assign_permission(): void
    {
        Role::create(['name' => 'form-editor', 'guard_name' => 'web'])
            ->givePermissionTo(['request_types.view', 'request_types.manage']);
        $editor = User::factory()->create()->assignRole('form-editor');
        $type = $this->typeWith([['key' => 'reason', 'label' => 'Reason', 'type' => RequestFieldType::ShortText]]);

        Livewire::actingAs($editor)->test('admin.request-types.builder')
            ->call('editType', $type->id)
            ->set('description', 'Clarified')
            ->call('save', false)
            ->assertHasNoErrors();

        $this->assertSame('Clarified', $type->fresh()->description);
    }

    public function test_assigning_a_built_in_workflow_is_authorized_on_the_page(): void
    {
        Role::create(['name' => 'form-viewer', 'guard_name' => 'web'])->givePermissionTo(['request_types.view']);
        $viewer = User::factory()->create()->assignRole('form-viewer');
        $general = RequestTypeVersion::general()->requestType;

        Livewire::actingAs($viewer)->test('pages::admin.request-types')
            ->call('assignBuiltInWorkflow', $general->id, '')
            ->assertForbidden();
    }

    public function test_the_workflow_label_helper_is_not_an_endpoint(): void
    {
        $this->expectException(MethodNotFoundException::class);

        Livewire::actingAs($this->admin)->test('pages::admin.request-types')->call('workflowLabel', 1);
    }

    /** @param list<array<string, mixed>> $fields */
    private function typeWith(array $fields): RequestType
    {
        $type = RequestType::factory()->create();
        $version = RequestTypeVersion::factory()->for($type)->create();
        foreach ($fields as $position => $field) {
            RequestFieldDefinition::factory()->for($version)->create([...$field, 'position' => $position]);
        }

        return $type;
    }
}
