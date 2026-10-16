<?php

namespace Tests\Feature\Schema;

use App\Support\SchemaTimestamps;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * RV-DATA-02: NOT NULL TIMESTAMP columns must not pick up MariaDB's implicit
 * DEFAULT/ON UPDATE CURRENT_TIMESTAMP when explicit_defaults_for_timestamp is OFF.
 */
class PortableTimestampColumnsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private const REQUIRED_TIMESTAMPS = [
        'workflow_instances' => ['started_at'],
        'workflow_actions' => ['created_at'],
        'delegations' => ['starts_at', 'expires_at'],
        'submission_nudges' => ['scheduled_for'],
        'user_out_of_office' => ['starts_at', 'ends_at'],
        'registration_otp_codes' => ['expires_at'],
        'auditor_accesses' => ['starts_at', 'ends_at'],
        'legal_holds' => ['placed_at'],
        'ops_snapshots' => ['recorded_at'],
        'ops_backup_verifications' => ['verified_at'],
    ];

    public function test_migrations_declare_no_bare_not_null_timestamp_columns(): void
    {
        $bare = [];

        foreach (File::files(database_path('migrations')) as $migration) {
            foreach (file($migration->getPathname()) ?: [] as $number => $line) {
                if (preg_match('/->timestamp(Tz)?\(\s*\'[a-z_]+\'[^)]*\)(?!.*(nullable|useCurrent|default\())/', $line)) {
                    $bare[] = $migration->getFilename().':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $bare, 'Use SchemaTimestamps::required() for NOT NULL timestamp columns: '.implode(', ', $bare));
    }

    public function test_required_timestamps_stay_plain_on_servers_without_implicit_defaults(): void
    {
        if (SchemaTimestamps::hasImplicitDefaults(DB::connection())) {
            $this->markTestSkipped('This server applies implicit TIMESTAMP defaults.');
        }

        $this->assertFalse(SchemaTimestamps::hasImplicitDefaults(DB::connection()));
    }

    public function test_required_timestamps_have_no_implicit_on_update_on_mysql_family_servers(): void
    {
        $connection = DB::connection();

        if (! $connection instanceof MySqlConnection) {
            $this->markTestSkipped('Column metadata check needs MySQL or MariaDB.');
        }

        foreach (self::REQUIRED_TIMESTAMPS as $table => $columns) {
            foreach ($columns as $column) {
                $meta = $connection->selectOne(
                    'select is_nullable, column_default, extra from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
                    [$table, $column],
                );

                $this->assertNotNull($meta, "{$table}.{$column} is missing.");
                $meta = array_change_key_case((array) $meta);
                $this->assertSame('NO', $meta['is_nullable'], "{$table}.{$column} must stay NOT NULL.");
                $this->assertStringNotContainsStringIgnoringCase('on update', (string) $meta['extra'], "{$table}.{$column} must not change on row update.");
                $this->assertStringNotContainsString('0000-00-00', (string) $meta['column_default'], "{$table}.{$column} must not default to a zero date.");
            }
        }
    }
}
