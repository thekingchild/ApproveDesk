<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class SchemaTable
{
    public static function bareName(string $table): string
    {
        $segment = str_contains($table, '.')
            ? substr($table, (int) strrpos($table, '.') + 1)
            : $table;

        return trim($segment, '`"[]');
    }

    public static function quoted(string $table): string
    {
        $name = self::bareName($table);

        return '`'.str_replace('`', '``', $name).'`';
    }

    /**
     * @return list<string>
     */
    public static function applicationTables(): array
    {
        $tables = array_map(
            self::bareName(...),
            Schema::getTableListing(schemaQualified: false),
        );
        sort($tables);

        return array_values(array_filter(
            $tables,
            fn (string $table): bool => $table !== 'migrations'
                && ! str_starts_with($table, 'sqlite_'),
        ));
    }
}
