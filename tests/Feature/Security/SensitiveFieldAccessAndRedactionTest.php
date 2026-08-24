<?php

namespace Tests\Feature\Security;

use App\Actions\Audit\GenerateEvidencePackAction;
use App\Actions\RequestTypes\PublishRequestTypeVersionAction;
use App\Actions\RequestTypes\StoreSubmissionFieldValuesAction;
use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;
use App\Models\RequestType;
use App\Models\RequestTypeVersion;
use App\Models\SensitiveFieldAccess;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Privacy\ExportRedactor;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class SensitiveFieldAccessAndRedactionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::query()->delete();
        Artisan::call('cache:clear');
    }

    public function test_opening_a_sensitive_field_is_recorded_for_an_administrator_not_the_owner(): void
    {
        [$submission, $owner] = $this->submissionWithSensitiveField();
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($owner)->get(route('submissions.show', $submission))->assertOk();
        $this->assertSame(0, SensitiveFieldAccess::query()->count());

        $this->actingAs($admin)->get(route('submissions.show', $submission))->assertOk()->assertSee('diagnosis-velvet-913');
        $this->assertSame(1, SensitiveFieldAccess::query()->where('context', 'view')->count());
    }

    public function test_evidence_pack_redacts_sensitive_values_without_permission(): void
    {
        Storage::fake('local');
        [$submission] = $this->submissionWithSensitiveField();
        $stranger = User::factory()->create()->assignRole('viewer');
        $admin = User::factory()->create()->assignRole('admin');

        $this->assertTrue((bool) $submission->fieldValues->first()?->field?->is_sensitive);
        $this->assertFalse($stranger->can('submissions.fields.sensitive'));

        $redacted = app(GenerateEvidencePackAction::class)->execute($submission, $stranger);
        $visible = app(GenerateEvidencePackAction::class)->execute($submission, $admin);

        $this->assertStringContainsString(ExportRedactor::PLACEHOLDER, $this->packRequestJson($redacted->stored_path));
        $this->assertStringNotContainsString('diagnosis-velvet-913', $this->packRequestJson($redacted->stored_path));
        $this->assertStringContainsString('diagnosis-velvet-913', $this->packRequestJson($visible->stored_path));
    }

    /**
     * @return array{0: Submission, 1: User}
     */
    private function submissionWithSensitiveField(): array
    {
        $type = RequestType::factory()->create();
        $version = RequestTypeVersion::factory()->for($type)->create();
        RequestFieldDefinition::factory()->for($version)->create([
            'key' => 'medical_note',
            'label' => 'Medical note',
            'type' => RequestFieldType::LongText,
            'is_sensitive' => true,
            'is_searchable' => true,
        ]);
        app(PublishRequestTypeVersionAction::class)->execute($version);

        $owner = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($owner, 'submitter')->create([
            'request_type_version_id' => $version->fresh()->id,
        ]);
        app(StoreSubmissionFieldValuesAction::class)->execute($submission, [
            'medical_note' => 'diagnosis-velvet-913',
        ]);
        app(WorkflowEngine::class)->initialize($submission);

        return [$submission->fresh(['fieldValues.field', 'requestTypeVersion.fields']), $owner];
    }

    private function packRequestJson(string $relative): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($relative)));
        $json = (string) $zip->getFromName('request.json');
        $zip->close();

        return $json;
    }
}
