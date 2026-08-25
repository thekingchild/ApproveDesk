<?php

namespace Tests\Unit\Support;

use App\Support\SchemaTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_name_strips_a_mysql_schema_prefix(): void
    {
        $this->assertSame('activity_log', SchemaTable::bareName('approvedesk_test.activity_log'));
        $this->assertSame('activity_log', SchemaTable::bareName('`approvedesk_test`.`activity_log`'));
        $this->assertSame('activity_log', SchemaTable::bareName('`approvedesk_test.activity_log`'));
        $this->assertSame('activity_log', SchemaTable::bareName('activity_log'));
    }

    public function test_quoted_name_never_wraps_schema_and_table_as_one_identifier(): void
    {
        $this->assertSame('`activity_log`', SchemaTable::quoted('approvedesk_test.activity_log'));
        $this->assertSame('`activity_log`', SchemaTable::quoted('`approvedesk_test`.`activity_log`'));
        $this->assertSame('`activity_log`', SchemaTable::quoted('activity_log'));
    }

    public function test_application_tables_are_never_schema_qualified(): void
    {
        $tables = SchemaTable::applicationTables();

        $this->assertContains('activity_log', $tables);
        $this->assertContains('users', $tables);
        $this->assertNotContains('migrations', $tables);

        foreach ($tables as $table) {
            $this->assertStringNotContainsString('.', $table);
        }
    }
}
