<?php

namespace Tests\Feature\RequestTypes;

use App\Models\RequestType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VersionedRequestSchemaMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const FIRST_POST_V21_MIGRATION = '2026_08_19_010000_create_versioned_request_schema.php';

    public function test_v21_database_fixture_is_upgraded_without_changing_reference_or_workflow_history(): void
    {
        foreach ($this->postV21MigrationPaths() as $path) {
            $this->artisan('migrate:rollback', [
                '--path' => $path,
                '--force' => true,
            ])->assertExitCode(0);
        }

        $this->assertFalse(Schema::hasTable('request_types'));
        DB::unprepared((string) file_get_contents(base_path('tests/Fixtures/approvedesk-v2.1.sql')));
        $this->assertDatabaseHas('submissions', ['id' => 9401, 'reference_number' => 'REQ-V21-0001']);
        $this->assertDatabaseHas('workflow_actions', ['id' => 9601, 'comment' => 'Preserved v2.1 approval.']);

        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $generalVersionId = DB::table('request_type_versions')
            ->join('request_types', 'request_types.id', '=', 'request_type_versions.request_type_id')
            ->where('request_types.slug', RequestType::GENERAL_SLUG)
            ->value('request_type_versions.id');

        $this->assertNotNull($generalVersionId);
        $this->assertDatabaseHas('submissions', [
            'id' => 9401,
            'reference_number' => 'REQ-V21-0001',
            'request_type_version_id' => $generalVersionId,
        ]);
        $this->assertDatabaseHas('workflow_actions', ['id' => 9601, 'comment' => 'Preserved v2.1 approval.']);
        $this->assertSame(5, DB::table('submission_field_values')->where('submission_id', 9401)->count());
        $this->assertDatabaseHas('submission_field_values', [
            'submission_id' => 9401,
            'text_value' => '<p>Preserve the fixture body.</p>',
        ]);
        $this->assertTrue(Schema::hasColumns('request_field_definitions', ['is_searchable', 'is_sensitive']));
        $this->assertTrue(Schema::hasTable('submission_saved_views'));
        $this->assertTrue(Schema::hasColumn('request_types', 'workflow_template_id'));
        $this->assertDatabaseHas('request_types', [
            'slug' => RequestType::GENERAL_SLUG,
            'workflow_template_id' => 9201,
        ]);
        $this->assertDatabaseHas('workflow_instances', [
            'id' => 9501,
            'template_id' => 9201,
        ]);
        $this->assertTrue(Schema::hasTable('workflow_template_versions'));
        $this->assertDatabaseHas('workflow_template_steps', [
            'id' => 9301,
            'template_id' => 9201,
        ]);
        $this->assertNotNull(DB::table('workflow_instances')->where('id', 9501)->value('template_version_id'));
        $this->assertSame(
            DB::table('workflow_instances')->where('id', 9501)->value('template_version_id'),
            DB::table('workflow_template_steps')->where('id', 9301)->value('template_version_id'),
        );
        $this->assertTrue(Schema::hasColumn('users', 'manager_id'));
        $this->assertTrue(Schema::hasTable('workflow_selection_rules'));
        $this->assertSame('first_response', DB::table('workflow_template_steps')->where('id', 9301)->value('completion_rule'));
        $this->assertTrue(Schema::hasColumn('users', 'phone'));
        $this->assertTrue(Schema::hasTable('sensitive_field_accesses'));
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
