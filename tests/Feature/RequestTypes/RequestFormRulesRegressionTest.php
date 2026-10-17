<?php

namespace Tests\Feature\RequestTypes;

use App\Actions\RequestTypes\ArchiveRequestTypeAction;
use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\SaveRequestTypeDraftAction;
use App\Actions\RequestTypes\StoreSubmissionFieldValuesAction;
use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\RequestTypes\RequestSchemaCompiler;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Custom request form rules: RV-BUG-29 (hidden parent's stale value),
 * RV-BUG-30 (review total vs stored total), RV-BUG-31 (draft save re-opens
 * an archived type), RV-BUG-35 (date-time latest date), RV-BUG-36 (required
 * checkbox).
 */
class RequestFormRulesRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** @param list<array<string, mixed>> $fields */
    private function version(array $fields, ?RequestType $type = null): RequestTypeVersion
    {
        $version = RequestTypeVersion::factory()->for($type ?? RequestType::factory()->create())->create();
        foreach ($fields as $position => $field) {
            RequestFieldDefinition::factory()->for($version)->create([...$field, 'position' => $position, 'label' => ucfirst($field['key'])]);
        }
        app(PublishRequestTypeVersionAction::class)->execute($version);

        return $version->fresh('fields');
    }

    private function travelForm(): RequestTypeVersion
    {
        return $this->version([
            ['key' => 'kind', 'type' => RequestFieldType::Select, 'configuration' => ['options' => ['travel', 'other']]],
            ['key' => 'mode', 'type' => RequestFieldType::Select, 'configuration' => ['options' => ['air', 'rail'], 'visibility' => ['field' => 'kind', 'operator' => 'equals', 'value' => 'travel']]],
            ['key' => 'flight', 'type' => RequestFieldType::ShortText, 'is_required' => true, 'configuration' => ['visibility' => ['field' => 'mode', 'operator' => 'equals', 'value' => 'air']]],
            ['key' => 'seat', 'type' => RequestFieldType::ShortText, 'configuration' => ['required_when' => ['field' => 'mode', 'operator' => 'equals', 'value' => 'air']]],
        ]);
    }

    public function test_a_hidden_parent_value_does_not_show_or_require_its_descendants(): void
    {
        $version = $this->travelForm();
        $compiler = app(RequestSchemaCompiler::class);
        // Picked travel -> air, then switched kind to "other": mode is hidden but its value lingers.
        $values = ['kind' => 'other', 'mode' => 'air'];

        $this->assertSame(['kind' => 'other'], $compiler->validate($version, $values));
        $this->assertSame([], $compiler->missingRequired($version, $values));
        $this->assertSame(['kind' => 'other'], $compiler->visibleValues($version, $values));
        $this->assertFalse($compiler->isVisible($version->fields[2], $compiler->visibleValues($version, $values)));
        $this->assertFalse($compiler->isRequired($version->fields[3], $compiler->visibleValues($version, $values)));

        $submission = Submission::factory()->create(['request_type_version_id' => $version->id, 'status' => 'draft']);
        app(StoreSubmissionFieldValuesAction::class)->execute($submission, [...$values, 'flight' => 'BA123']);
        $this->assertSame(['kind'], $submission->fieldValues()->with('field')->get()->pluck('field.key')->all());
    }

    public function test_a_visible_chain_still_requires_the_descendant(): void
    {
        $version = $this->travelForm();
        $compiler = app(RequestSchemaCompiler::class);

        $this->assertSame(['Flight', 'Seat'], $compiler->missingRequired($version, ['kind' => 'travel', 'mode' => 'air']));

        try {
            $compiler->validate($version, ['kind' => 'travel', 'mode' => 'air']);
            $this->fail('flight was not required');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('values.flight', $exception->errors());
            $this->assertArrayHasKey('values.seat', $exception->errors());
        }
    }

    public function test_the_custom_form_hides_descendants_of_a_hidden_parent(): void
    {
        WorkflowTemplate::factory()->default()->sequential([User::factory()->create()])->create();
        $user = User::factory()->create()->assignRole('submitter');
        $type = RequestType::factory()->create();
        $this->version([
            ['key' => 'kind', 'type' => RequestFieldType::Select, 'configuration' => ['options' => ['travel', 'other']]],
            ['key' => 'mode', 'type' => RequestFieldType::Select, 'configuration' => ['options' => ['air', 'rail'], 'visibility' => ['field' => 'kind', 'operator' => 'equals', 'value' => 'travel']]],
            ['key' => 'flight', 'type' => RequestFieldType::ShortText, 'is_required' => true, 'configuration' => ['visibility' => ['field' => 'mode', 'operator' => 'equals', 'value' => 'air']]],
        ], $type);

        Livewire::actingAs($user)->test('pages::submissions.create-custom', ['requestType' => $type])
            ->set('values.kind', 'travel')->set('values.mode', 'air')
            ->assertSee('dusk="custom-field-flight"', false)
            ->set('values.kind', 'other')
            ->assertDontSee('dusk="custom-field-flight"', false)
            ->call('review')->assertHasNoErrors()->assertSet('step', 2)
            ->assertSee('<dt class="font-medium">Kind</dt>', false)
            ->assertDontSee('<dt class="font-medium">Mode</dt>', false)
            ->assertDontSee('<dt class="font-medium">Flight</dt>', false);
    }

    public function test_builder_preview_hides_descendants_of_a_hidden_parent(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $type = RequestType::factory()->create();
        $version = RequestTypeVersion::factory()->for($type)->create();
        foreach ([
            ['key' => 'kind', 'type' => RequestFieldType::Select, 'configuration' => ['options' => ['travel', 'other']]],
            ['key' => 'mode', 'type' => RequestFieldType::Select, 'configuration' => ['options' => ['air', 'rail'], 'visibility' => ['field' => 'kind', 'operator' => 'equals', 'value' => 'travel']]],
            ['key' => 'flight', 'type' => RequestFieldType::ShortText, 'configuration' => [
                'visibility' => ['field' => 'mode', 'operator' => 'equals', 'value' => 'air'],
                'required_when' => ['field' => 'mode', 'operator' => 'equals', 'value' => 'air'],
            ]],
        ] as $position => $field) {
            RequestFieldDefinition::factory()->for($version)->create([...$field, 'position' => $position, 'label' => ucfirst($field['key'])]);
        }

        $component = Livewire::actingAs($admin)->test('admin.request-types.builder')
            ->call('editType', $type->id)
            ->set('previewValues.kind', 'travel')->set('previewValues.mode', 'air');
        $this->assertTrue($component->instance()->previewState[2]['visible']);
        $this->assertTrue($component->instance()->previewState[2]['required']);

        $component->set('previewValues.kind', 'other');
        $this->assertFalse($component->instance()->previewState[1]['visible']);
        $this->assertFalse($component->instance()->previewState[2]['visible']);
        $this->assertFalse($component->instance()->previewState[2]['required']);
    }

    public function test_review_total_matches_the_stored_total_when_a_source_is_hidden(): void
    {
        $version = $this->version([
            ['key' => 'has_extra', 'type' => RequestFieldType::Select, 'configuration' => ['options' => ['yes', 'no']]],
            ['key' => 'base', 'type' => RequestFieldType::Number],
            ['key' => 'extra', 'type' => RequestFieldType::Number, 'configuration' => ['visibility' => ['field' => 'has_extra', 'operator' => 'equals', 'value' => 'yes']]],
            ['key' => 'total', 'type' => RequestFieldType::Calculated, 'configuration' => ['calculation' => ['operation' => 'sum', 'fields' => ['base', 'extra']]]],
        ]);
        $values = ['has_extra' => 'no', 'base' => 100, 'extra' => 900];
        $compiler = app(RequestSchemaCompiler::class);

        $shown = $compiler->withCalculatedValues($version, $values);
        $submission = Submission::factory()->create(['request_type_version_id' => $version->id, 'status' => 'draft']);
        $stored = app(StoreSubmissionFieldValuesAction::class)->execute($submission, $values);

        $this->assertEquals(100, $shown['total']);
        $this->assertEquals(100, $stored['total']);
        $this->assertSame(900, $shown['extra'], 'the hidden answer stays in the form state in case the user switches back');
        $this->assertEquals(1000, $compiler->withCalculatedValues($version, [...$values, 'has_extra' => 'yes'])['total']);
    }

    public function test_review_screen_shows_the_total_that_will_be_stored(): void
    {
        WorkflowTemplate::factory()->default()->sequential([User::factory()->create()])->create();
        $user = User::factory()->create()->assignRole('submitter');
        $type = RequestType::factory()->create();
        $this->version([
            ['key' => 'has_extra', 'type' => RequestFieldType::Select, 'configuration' => ['options' => ['yes', 'no']]],
            ['key' => 'base', 'type' => RequestFieldType::Number],
            ['key' => 'extra', 'type' => RequestFieldType::Number, 'configuration' => ['visibility' => ['field' => 'has_extra', 'operator' => 'equals', 'value' => 'yes']]],
            ['key' => 'total', 'type' => RequestFieldType::Calculated, 'configuration' => ['calculation' => ['operation' => 'sum', 'fields' => ['base', 'extra']]]],
        ], $type);

        $component = Livewire::actingAs($user)->test('pages::submissions.create-custom', ['requestType' => $type])
            ->set('values.has_extra', 'yes')->set('values.base', 100)->set('values.extra', 900)
            ->set('values.has_extra', 'no')
            ->call('review')->assertHasNoErrors();

        $this->assertEquals(100, $component->get('values.total'));
        $component->call('saveDraft');
        $submission = Submission::query()->where('submitter_id', $user->id)->sole();
        $total = $submission->fieldValues()->whereHas('field', fn ($query) => $query->where('key', 'total'))->sole();
        $this->assertEquals(100, $total->resolvedValue());
    }

    public function test_saving_a_draft_keeps_an_archived_type_archived_until_restored(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $type = RequestType::factory()->create();
        $this->version([['key' => 'reason', 'type' => RequestFieldType::ShortText]], $type);
        app(ArchiveRequestTypeAction::class)->execute($type);

        app(SaveRequestTypeDraftAction::class)->execute($type->fresh(), $type->name, null, [
            ['key' => 'reason', 'label' => 'Reason', 'type' => 'short_text'],
            ['key' => 'notes', 'label' => 'Notes', 'type' => 'long_text'],
        ], $admin);
        $this->assertFalse($type->fresh()->is_active);

        Livewire::actingAs($admin)->test('pages::admin.request-types')
            ->assertSee(__('Restore'))
            ->call('restore', $type->id)->assertHasNoErrors();
        $this->assertTrue($type->fresh()->is_active);
    }

    public function test_publishing_from_the_builder_keeps_an_archived_type_archived(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $type = RequestType::factory()->create();
        $this->version([['key' => 'reason', 'type' => RequestFieldType::ShortText]], $type);
        app(ArchiveRequestTypeAction::class)->execute($type);

        Livewire::actingAs($admin)->test('admin.request-types.builder')
            ->call('editType', $type->id)
            ->call('save', true)->assertHasNoErrors();

        $this->assertFalse($type->fresh()->is_active);
        $this->assertSame(2, (int) $type->versions()->max('version'));
    }

    public function test_requesters_cannot_restore_a_request_type(): void
    {
        $type = RequestType::factory()->create(['is_active' => false]);

        Livewire::actingAs(User::factory()->create()->assignRole('submitter'))
            ->test('pages::admin.request-types')
            ->assertForbidden();

        $this->assertFalse($type->fresh()->is_active);
    }

    public function test_date_time_latest_date_includes_that_whole_day(): void
    {
        $version = $this->version([
            ['key' => 'when', 'type' => RequestFieldType::DateTime, 'configuration' => ['min' => '2026-12-01', 'max' => '2026-12-31']],
        ]);
        $compiler = app(RequestSchemaCompiler::class);

        $this->assertSame('2026-12-31T23:59', $compiler->validate($version, ['when' => '2026-12-31T23:59'])['when']);
        $this->assertSame('2026-12-01T00:00', $compiler->validate($version, ['when' => '2026-12-01T00:00'])['when']);

        foreach (['2027-01-01T00:00', '2026-11-30T23:59'] as $outside) {
            try {
                $compiler->validate($version, ['when' => $outside]);
                $this->fail("{$outside} was accepted");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('values.when', $exception->errors());
            }
        }
    }

    public function test_date_field_and_date_time_with_explicit_time_keep_their_bounds(): void
    {
        $version = $this->version([
            ['key' => 'day', 'type' => RequestFieldType::Date, 'configuration' => ['max' => '2026-12-31']],
            ['key' => 'when', 'type' => RequestFieldType::DateTime, 'configuration' => ['max' => '2026-12-31 12:00']],
        ]);
        $compiler = app(RequestSchemaCompiler::class);

        $this->assertSame('2026-12-31', $compiler->validate($version, ['day' => '2026-12-31'])['day']);
        $this->expectException(ValidationException::class);
        $compiler->validate($version, ['when' => '2026-12-31T15:00']);
    }

    public function test_required_checkbox_must_be_ticked(): void
    {
        $version = $this->version([
            ['key' => 'agree', 'type' => RequestFieldType::Checkbox, 'is_required' => true],
        ]);
        $compiler = app(RequestSchemaCompiler::class);

        foreach ([false, null, '0'] as $unticked) {
            try {
                $compiler->validate($version, ['agree' => $unticked]);
                $this->fail('an unticked required checkbox was accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('values.agree', $exception->errors());
            }
        }

        $this->assertTrue($compiler->validate($version, ['agree' => true])['agree']);
        $this->assertSame(['Agree'], $compiler->missingRequired($version, ['agree' => false]));
        $this->assertSame([], $compiler->missingRequired($version, ['agree' => true]));
        // Drafts may still be saved unticked.
        $this->assertFalse($compiler->validate($version, ['agree' => false], enforceRequired: false)['agree']);
    }

    public function test_optional_checkbox_may_be_left_unticked(): void
    {
        $version = $this->version([
            ['key' => 'urgent', 'type' => RequestFieldType::Checkbox],
        ]);

        $this->assertFalse(app(RequestSchemaCompiler::class)->validate($version, ['urgent' => false])['urgent']);
        $this->assertSame([], app(RequestSchemaCompiler::class)->missingRequired($version, ['urgent' => false]));
    }
}
