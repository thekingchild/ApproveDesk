<?php

namespace Tests\Feature\Workflow;

use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class WorkflowVersionUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    private const FIRST_POST_V21_MIGRATION = '2026_08_19_010000_create_versioned_request_schema.php';

    protected function tearDown(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);

        parent::tearDown();
    }

    public function test_v21_in_flight_instance_completes_on_its_original_version(): void
    {
        foreach ($this->postV21MigrationPaths() as $path) {
            $this->artisan('migrate:rollback', ['--path' => $path, '--force' => true])->assertExitCode(0);
        }

        $this->assertFalse(Schema::hasTable('workflow_template_versions'));
        DB::unprepared((string) file_get_contents(base_path('tests/Fixtures/approvedesk-v2.1-inflight.sql')));
        $this->assertDatabaseHas('workflow_instances', ['id' => 9501, 'status' => 'active', 'current_step_id' => 9301]);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $this->seed(RolesAndPermissionsSeeder::class);

        $instance = WorkflowInstance::query()->findOrFail(9501);
        $originalVersionId = $instance->template_version_id;
        $this->assertNotNull($originalVersionId);
        $this->assertSame($originalVersionId, DB::table('workflow_template_steps')->where('id', 9301)->value('template_version_id'));
        $this->assertSame(9301, $instance->current_step_id);

        $first = User::query()->findOrFail(9102);
        $second = User::query()->findOrFail(9103);
        $engine = app(WorkflowEngine::class);
        $engine->processAction($instance, $first, WorkflowActionType::Approve, 'Manager approved after upgrade.');

        $instance->refresh();
        $this->assertSame(9302, $instance->current_step_id);
        $this->assertSame($originalVersionId, $instance->template_version_id);

        $admin = User::factory()->create()->assignRole('admin');
        Livewire::actingAs($admin)
            ->test('pages::admin.workflow-builder', ['template' => 9201])
            ->call('addNode', 'approval')
            ->set('steps.2.assigneeIds', [$second->id])
            ->set('changeSummary', 'Add a third review after upgrade.')
            ->call('requestPublish')
            ->call('confirmPublish')
            ->assertHasNoErrors();

        $template = WorkflowTemplate::query()->findOrFail(9201);
        $this->assertSame(2, $template->publishedVersion?->version);
        $this->assertSame($originalVersionId, $instance->fresh()->template_version_id);
        $this->assertSame('Director review', $instance->fresh()->currentStep?->name);

        $engine->processAction($instance->fresh(), $second, WorkflowActionType::Approve, 'Director approved original version.');

        $instance->refresh();
        $this->assertSame(WorkflowStatus::Completed, $instance->status);
        $this->assertSame($originalVersionId, $instance->template_version_id);
        $this->assertDatabaseHas('workflow_actions', [
            'instance_id' => 9501,
            'step_id' => 9301,
            'comment' => 'Manager approved after upgrade.',
        ]);
        $this->assertDatabaseHas('workflow_actions', [
            'instance_id' => 9501,
            'step_id' => 9302,
            'comment' => 'Director approved original version.',
        ]);

        $next = $engine->initialize(Submission::factory()->create());
        $this->assertSame($template->fresh()->publishedVersion?->id, $next->template_version_id);
        $this->assertNotSame($originalVersionId, $next->template_version_id);
    }

    /**
     * @return list<string>
     */
    private function postV21MigrationPaths(): array
    {
        $paths = collect(File::files(database_path('migrations')))
            ->map(fn (\SplFileInfo $file): string => $file->getFilename())
            ->filter(fn (string $name): bool => $name >= self::FIRST_POST_V21_MIGRATION)
            ->sort()
            ->reverse()
            ->values()
            ->map(fn (string $name): string => 'database/migrations/'.$name)
            ->all();

        $this->assertNotEmpty($paths);

        return $paths;
    }
}
