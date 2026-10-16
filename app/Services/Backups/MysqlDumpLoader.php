<?php

namespace App\Services\Backups;

use App\Support\SchemaTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces every application table on a MySQL/MariaDB connection with the
 * contents of an ApproveDesk SQL dump. MySQL DDL cannot run in a transaction,
 * so callers must hold a verified safety copy before calling this.
 */
class MysqlDumpLoader
{
    /** Streams the dump statement by statement instead of loading it whole (RV-OPS-03). */
    public function replaceFromFile(string $path): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach (SchemaTable::applicationTables() as $table) {
                Schema::dropIfExists($table);
            }

            foreach (SqlStatementStream::statements($path, true) as $statement) {
                DB::unprepared($statement);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
