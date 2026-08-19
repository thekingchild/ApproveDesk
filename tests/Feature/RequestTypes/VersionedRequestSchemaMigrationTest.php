<?php

namespace Tests\Feature\RequestTypes;

use App\Models\RequestType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VersionedRequestSchemaMigrationTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION_PATH = 'database/migrations/2026_08_19_010000_create_versioned_request_schema.php';

    private const SEARCH_MIGRATION_PATH = 'database/migrations/2026_08_19_020000_add_request_field_discovery_and_saved_views.php';

    public function test_v21_database_fixture_is_upgraded_without_changing_reference_or_workflow_history(): void
    {
        $this->artisan('migrate:rollback', [
            '--path' => self::SEARCH_MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('migrate:rollback', [
            '--path' => self::MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('request_types'));
        DB::unprepared((string) file_get_contents(base_path('tests/Fixtures/approvedesk-v2.1.sql')));
        $this->assertDatabaseHas('submissions', ['id' => 9401, 'reference_number' => 'REQ-V21-0001']);
        $this->assertDatabaseHas('workflow_actions', ['id' => 9601, 'comment' => 'Preserved v2.1 approval.']);

        $this->artisan('migrate', [
            '--path' => self::MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);
        $this->artisan('migrate', [
            '--path' => self::SEARCH_MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

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
    }
}
