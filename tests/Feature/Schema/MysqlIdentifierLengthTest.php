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

    public function test_workflow_template_steps_keep_a_standalone_template_id_index(): void
    {
        $standalone = collect(Schema::getIndexes('workflow_template_steps'))
            ->first(fn (array $index): bool => ($index['columns'] ?? []) === ['template_id']);

        $this->assertNotNull(
            $standalone,
            'template_id needs its own index so MySQL can drop the old (template_id, step_order) unique without error 1553.',
        );
    }

    public function test_push_subscriptions_do_not_unique_index_the_text_endpoint(): void
    {
        $uniques = collect(Schema::getIndexes('push_subscriptions'))
            ->filter(fn (array $index): bool => ($index['unique'] ?? false) === true);

        foreach ($uniques as $index) {
            $this->assertNotContains(
                'endpoint',
                $index['columns'] ?? [],
                'MySQL error 1170: TEXT endpoint cannot be in a unique key. Index a bounded hash instead.',
            );
        }

        $hashUnique = $uniques->first(
            fn (array $index): bool => ($index['columns'] ?? []) === ['user_id', 'endpoint_hash'],
        );

        $this->assertNotNull(
            $hashUnique,
            'push_subscriptions must uniquely identify a device by user_id + endpoint_hash.',
        );
        $this->assertLessThanOrEqual(64, strlen((string) ($hashUnique['name'] ?? '')));
    }
}
