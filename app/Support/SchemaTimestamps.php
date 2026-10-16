<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * Portable NOT NULL TIMESTAMP columns.
 *
 * MariaDB before 10.10 defaults to explicit_defaults_for_timestamp=OFF (a
 * global-only setting): the first NOT NULL TIMESTAMP in a table silently
 * becomes DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP and later
 * ones get a zero-date default that strict mode rejects. An explicit
 * DEFAULT CURRENT_TIMESTAMP suppresses both. Other servers keep the plain
 * column so their schema is unchanged. See ADR 0004.
 */
final class SchemaTimestamps
{
    /** @var array<string, bool> */
    private static array $implicitDefaults = [];

    public static function required(Blueprint $table, string $column): ColumnDefinition
    {
        $definition = $table->timestamp($column);

        if (self::hasImplicitDefaults(Schema::getConnection())) {
            $definition->useCurrent();
        }

        return $definition;
    }

    public static function hasImplicitDefaults(Connection $connection): bool
    {
        if (! $connection instanceof MySqlConnection || ! $connection->isMaria()) {
            return false;
        }

        return self::$implicitDefaults[$connection->getName()] ??= ! (bool) $connection
            ->selectOne('select @@explicit_defaults_for_timestamp as explicit_defaults')
            ->explicit_defaults;
    }
}
