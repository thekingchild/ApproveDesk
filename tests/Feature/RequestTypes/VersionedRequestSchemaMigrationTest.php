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

    private const WORKFLOW_ASSIGNMENT_MIGRATION_PATH = 'database/migrations/2026_08_19_030000_add_workflow_template_to_request_types.php';

    private const WORKFLOW_VERSION_MIGRATION_PATH = 'database/migrations/2026_08_19_040000_create_workflow_template_versions.php';

    private const DYNAMIC_ROUTING_MIGRATION_PATH = 'database/migrations/2026_08_19_050000_add_dynamic_routing_and_assignment.php';

    private const USER_PHONE_MIGRATION_PATH = 'database/migrations/2026_08_20_060000_add_phone_to_users_table.php';

    public function test_v21_database_fixture_is_upgraded_without_changing_reference_or_workflow_history(): void
    {
        $this->artisan('migrate:rollback', [
            '--path' => self::USER_PHONE_MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('migrate:rollback', [
            '--path' => self::DYNAMIC_ROUTING_MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('migrate:rollback', [
            '--path' => self::WORKFLOW_VERSION_MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $this->artisan('migrate:rollback', [
            '--path' => self::WORKFLOW_ASSIGNMENT_MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

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
        $this->artisan('migrate', [
            '--path' => self::WORKFLOW_ASSIGNMENT_MIGRATION_PATH,
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
        $this->assertTrue(Schema::hasColumn('request_types', 'workflow_template_id'));
        $this->assertDatabaseHas('request_types', [
            'slug' => RequestType::GENERAL_SLUG,
            'workflow_template_id' => 9201,
        ]);
        $this->assertDatabaseHas('workflow_instances', [
            'id' => 9501,
            'template_id' => 9201,
        ]);

        $this->artisan('migrate', [
            '--path' => self::WORKFLOW_VERSION_MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue(Schema::hasTable('workflow_template_versions'));
        $this->assertDatabaseHas('workflow_template_steps', [
            'id' => 9301,
            'template_id' => 9201,
        ]);
        $this->assertDatabaseHas('workflow_actions', ['id' => 9601, 'comment' => 'Preserved v2.1 approval.']);
        $this->assertNotNull(DB::table('workflow_instances')->where('id', 9501)->value('template_version_id'));
        $this->assertSame(
            DB::table('workflow_instances')->where('id', 9501)->value('template_version_id'),
            DB::table('workflow_template_steps')->where('id', 9301)->value('template_version_id'),
        );

        $this->artisan('migrate', [
            '--path' => self::DYNAMIC_ROUTING_MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('users', 'manager_id'));
        $this->assertTrue(Schema::hasTable('workflow_selection_rules'));
        $this->assertDatabaseHas('workflow_actions', ['id' => 9601, 'comment' => 'Preserved v2.1 approval.']);
        $this->assertSame('first_response', DB::table('workflow_template_steps')->where('id', 9301)->value('completion_rule'));

        $this->artisan('migrate', [
            '--path' => self::USER_PHONE_MIGRATION_PATH,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue(Schema::hasColumn('users', 'phone'));
    }
}
