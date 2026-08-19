<?php

namespace Tests\Feature\RequestTypes;

use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\StoreSubmissionFieldValuesAction;
use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\AnalyticsService;
use App\Services\SubmissionSearchService;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SearchAndReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_field_discovery_flags_are_published_as_part_of_the_immutable_schema(): void
    {
        [$type, $version, $public] = $this->requestSchema();

        $this->assertTrue($public->is_searchable);
        $this->assertTrue($public->is_filterable);
        $this->assertTrue($public->is_reportable);
        $this->assertTrue($public->is_approver_summary);
        $this->assertFalse($public->is_sensitive);
        $this->assertNotNull($version->fresh()->schema_hash);
        $this->assertSame($type->id, $version->request_type_id);
    }

    public function test_search_and_submission_detail_do_not_disclose_sensitive_fields_to_an_approver(): void
    {
        [$type, $version] = $this->requestSchema();
        $owner = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($owner, 'submitter')->create([
            'request_type_version_id' => $version->id,
            'title' => 'Private leave request',
        ]);
        app(StoreSubmissionFieldValuesAction::class)->execute($submission, [
            'destination' => 'Abuja',
            'medical_note' => 'diagnosis-velvet-913',
        ]);
        app(WorkflowEngine::class)->initialize($submission);

        $search = app(SubmissionSearchService::class);

        $this->assertTrue($search->search($owner, 'diagnosis-velvet')->contains($submission));
        $this->assertFalse($search->search($approver, 'diagnosis-velvet')->contains($submission));
        $this->assertTrue($search->search($approver, 'Abuja')->contains($submission));

        $this->actingAs($approver)
            ->get(route('submissions.show', $submission))
            ->assertOk()
            ->assertSee('Abuja')
            ->assertDontSee('diagnosis-velvet-913');
    }

    public function test_request_type_field_filters_columns_and_saved_views_are_user_scoped(): void
    {
        [$type, $version] = $this->requestSchema();
        $admin = User::factory()->create()->assignRole('admin');
        $otherAdmin = User::factory()->create()->assignRole('admin');
        $submission = Submission::factory()->create([
            'request_type_version_id' => $version->id,
            'title' => 'Travel request',
        ]);
        app(StoreSubmissionFieldValuesAction::class)->execute($submission, [
            'destination' => 'Kano',
            'medical_note' => 'private',
        ]);

        Livewire::actingAs($admin)->test('pages::submissions.index')
            ->set('requestTypeFilter', (string) $type->id)
            ->set('fieldFilters.destination', 'Kano')
            ->set('selectedColumns', ['destination'])
            ->set('savedViewName', 'Kano travel')
            ->call('saveCustomView')
            ->assertHasNoErrors()
            ->assertSee($submission->reference_number)
            ->assertSee('Kano');

        $this->assertDatabaseHas('submission_saved_views', [
            'user_id' => $admin->id,
            'request_type_id' => $type->id,
            'name' => 'Kano travel',
        ]);
        $this->assertCount(0, $otherAdmin->submissionSavedViews);
    }

    public function test_analytics_uses_reportable_fields_and_requires_sensitive_field_permission(): void
    {
        [$type, $version] = $this->requestSchema();
        $admin = User::factory()->create()->assignRole('admin');
        $reportViewer = User::factory()->create();
        $reportViewer->givePermissionTo('reports.view');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create(['request_type_version_id' => $version->id]);
        app(StoreSubmissionFieldValuesAction::class)->execute($submission, [
            'destination' => 'Lagos',
            'medical_note' => 'restricted-value',
        ]);
        app(WorkflowEngine::class)->initialize($submission);

        $adminFields = collect(app(AnalyticsService::class)->dashboard(30, $admin, $type->id)['custom_fields'])->keyBy('key');
        $viewerFields = collect(app(AnalyticsService::class)->dashboard(30, $reportViewer, $type->id)['custom_fields'])->keyBy('key');

        $this->assertTrue($adminFields->has('destination'));
        $this->assertTrue($adminFields->has('medical_note'));
        $this->assertTrue($viewerFields->has('destination'));
        $this->assertFalse($viewerFields->has('medical_note'));
    }

    /** @return array{RequestType, RequestTypeVersion, RequestFieldDefinition, RequestFieldDefinition} */
    private function requestSchema(): array
    {
        $type = RequestType::factory()->create(['name' => 'Travel Request']);
        $version = RequestTypeVersion::factory()->for($type)->create();
        $public = RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'destination',
            'label' => 'Destination',
            'type' => RequestFieldType::ShortText,
            'is_required' => true,
            'is_searchable' => true,
            'is_filterable' => true,
            'is_reportable' => true,
            'is_approver_summary' => true,
        ]);
        $sensitive = RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'medical_note',
            'label' => 'Medical note',
            'type' => RequestFieldType::LongText,
            'position' => 1,
            'is_searchable' => true,
            'is_reportable' => true,
            'is_sensitive' => true,
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($version);

        return [$type, $version->refresh(), $public->refresh(), $sensitive->refresh()];
    }
}
