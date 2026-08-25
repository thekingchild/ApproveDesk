<?php

namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MysqlIdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_and_foreign_key_names_fit_mysql_64_character_limit(): void
    {
        $limit = 64;
        $tooLong = [];

        foreach (Schema::getTableListing() as $table) {
            if (str_starts_with((string) $table, 'sqlite_')) {
                continue;
            }

            foreach (Schema::getIndexes($table) as $index) {
                $name = (string) ($index['name'] ?? '');

                if (strlen($name) > $limit) {
                    $tooLong[] = "{$table}.{$name} (".strlen($name).')';
                }
            }

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $name = (string) ($foreignKey['name'] ?? '');

                if ($name !== '' && strlen($name) > $limit) {
                    $tooLong[] = "{$table}.{$name} (".strlen($name).')';
                }
            }
        }

        $this->assertSame([], $tooLong, 'MySQL identifier names must be 64 characters or fewer.');
    }
}
