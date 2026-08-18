<?php

namespace Tests\Feature\RequestTypes;

use App\Enums\WorkflowActionType;
use App\Models\RequestType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VersionedRequestSchemaMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION_PATH = 'database/migrations/2026_08_19_010000_create_versioned_request_schema.php';

    public function test_v21_submission_is_backfilled_without_changing_reference_or_workflow_history(): void
    {
        $submitter = User::factory()->create();
        $approver = User::factory()->create();
        $template = WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create([
            'reference_number' => 'REQ-LEGACY-0001',
            'title' => 'Legacy purchase request',
            'body' => '<p>Preserve this body.</p>',
            'priority' => 'high',
        ]);
        $instance = app(WorkflowEngine::class)->initialize($submission);
        app(WorkflowEngine::class)->processAction($instance, $approver, WorkflowActionType::Approve, 'Preserved approval.');
        $actionId = WorkflowAction::query()->where('instance_id', $instance->id)->sole()->id;

        $this->artisan('migrate:rollback', [
            '--path' => self::MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('request_types'));
        $this->assertDatabaseHas('submissions', ['id' => $submission->id, 'reference_number' => 'REQ-LEGACY-0001']);
        $this->assertDatabaseHas('workflow_actions', ['id' => $actionId, 'comment' => 'Preserved approval.']);

        $this->artisan('migrate', [
            '--path' => self::MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $generalVersionId = DB::table('request_type_versions')
            ->join('request_types', 'request_types.id', '=', 'request_type_versions.request_type_id')
            ->where('request_types.slug', RequestType::GENERAL_SLUG)
            ->value('request_type_versions.id');

        $this->assertNotNull($generalVersionId);
        $this->assertDatabaseHas('submissions', [
            'id' => $submission->id,
            'reference_number' => 'REQ-LEGACY-0001',
            'request_type_version_id' => $generalVersionId,
        ]);
        $this->assertDatabaseHas('workflow_actions', ['id' => $actionId, 'comment' => 'Preserved approval.']);
        $this->assertSame(4, DB::table('submission_field_values')->where('submission_id', $submission->id)->count());
        $this->assertDatabaseHas('submission_field_values', [
            'submission_id' => $submission->id,
            'text_value' => '<p>Preserve this body.</p>',
        ]);
    }
}
