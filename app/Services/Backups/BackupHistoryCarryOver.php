<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The backup catalogue and restore history describe files and events that
 * exist outside the database: backups on disk and remotes, and restores that
 * happened. Promoting an older snapshot replaces every table, so capture these
 * three before the swap and put the live rows back afterwards; otherwise later
 * backups vanish from the list (and from retention) and the record of earlier
 * restores is lost (RV-DATA-10).
 */
final class BackupHistoryCarryOver
{
    /** Parents first; deleted in reverse. */
    private const TABLES = ['backup_destinations', 'backup_snapshots', 'backup_restore_events'];

    /** Nullable user references, cleared when the restored data has no such user. */
    private const USER_COLUMNS = [
        'backup_snapshots' => 'promoted_by_id',
        'backup_restore_events' => 'actor_id',
    ];

    /** @return array<string, list<array<string, mixed>>> */
    public function capture(): array
    {
        $captured = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = [];
            foreach (DB::table($table)->orderBy('id')->cursor() as $row) {
                $rows[] = get_object_vars($row);
            }
            $captured[$table] = $rows;
        }

        return $captured;
    }

    /**
     * Replace the restored copies with the captured live rows. The live rows
     * are a superset of the restored restore history (it is append-only), so
     * nothing recorded is dropped.
     *
     * @param  array<string, list<array<string, mixed>>>  $captured
     */
    public function restore(array $captured): void
    {
        DB::transaction(function () use ($captured): void {
            foreach (array_reverse(self::TABLES) as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }

            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table) || ($captured[$table] ?? []) === []) {
                    continue;
                }

                // An older snapshot may predate columns the live rows carry.
                $columns = array_flip(Schema::getColumnListing($table));
                $rows = array_map(
                    fn (array $row): array => array_intersect_key($row, $columns),
                    $captured[$table],
                );
                $rows = $this->withoutMissingUsers($table, $rows);

                foreach (array_chunk($rows, 100) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutMissingUsers(string $table, array $rows): array
    {
        $column = self::USER_COLUMNS[$table] ?? null;
        if ($column === null || ! Schema::hasColumn($table, $column)) {
            return $rows;
        }

        $referenced = array_values(array_unique(array_filter(array_column($rows, $column))));
        $present = $referenced === []
            ? []
            : array_flip(DB::table('users')->whereIn('id', $referenced)->pluck('id')->map(fn ($id): int => (int) $id)->all());

        return array_map(function (array $row) use ($column, $present): array {
            if ($row[$column] !== null && ! isset($present[(int) $row[$column]])) {
                $row[$column] = null;
            }

            return $row;
        }, $rows);
    }
}
