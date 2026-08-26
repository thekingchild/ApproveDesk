<?php

namespace App\Actions\Backups;

use App\Enums\BackupSnapshotKind;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupExecutionGuard;
use App\Services\Backups\BackupRecorder;
use App\Services\Operations\BackupVerifier;
use App\Support\BackupSettings;
use App\Support\SchemaTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class CreateDatabaseBackupAction
{
    public function __construct(
        private readonly BackupRecorder $recorder,
        private readonly BackupExecutionGuard $execution,
    ) {}

    public function execute(): BackupSnapshot
    {
        return $this->execution->run(fn (): BackupSnapshot => $this->create());
    }

    private function create(): BackupSnapshot
    {
        $dump = storage_path('app/private/backups/.tmp-'.uniqid('', true).'.sql');
        $gzip = $dump.'.gz';
        @mkdir(dirname($dump), 0775, true);

        try {
            $this->recorder->localDestination();
            $manifest = $this->writeSqlDump($dump);
            $this->recorder->gzipFile($dump, $gzip);

            return $this->recorder->store(
                BackupSnapshotKind::Database,
                $gzip,
                $manifest,
                BackupSettings::databaseRetainDays(),
            );
        } finally {
            foreach ([$dump, $gzip] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * @return array{tables: array<string, int>, workflow_actions: int}
     */
    private function writeSqlDump(string $path): array
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write database dump.');
        }

        $tables = [];
        fwrite($handle, "-- ApproveDesk database snapshot\nSET FOREIGN_KEY_CHECKS=0;\n");

        foreach ($this->tableNames() as $table) {
            fwrite($handle, $this->createStatement($table)."\n");
            $count = 0;
            foreach (DB::table($table)->cursor() as $row) {
                $count++;
                fwrite($handle, $this->insertStatement($table, (array) $row)."\n");
            }
            $tables[$table] = $count;
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        foreach (BackupVerifier::REQUIRED_TABLES as $required) {
            if (! array_key_exists($required, $tables)) {
                throw new RuntimeException('Dump is missing required table: '.$required);
            }
        }

        return [
            'tables' => $tables,
            'workflow_actions' => $tables['workflow_actions'] ?? 0,
        ];
    }

    /**
     * @return list<string>
     */
    private function tableNames(): array
    {
        return SchemaTable::applicationTables();
    }

    private function createStatement(string $table): string
    {
        $table = SchemaTable::bareName($table);

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $row = DB::selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);

            return rtrim((string) ($row->sql ?? 'CREATE TABLE '.$table.' (id INTEGER)'), ';').';';
        }

        $row = DB::selectOne('show create table '.SchemaTable::quoted($table));

        return rtrim((string) ($row->{'Create Table'} ?? ''), ';').';';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function insertStatement(string $table, array $row): string
    {
        $table = SchemaTable::quoted($table);
        $columns = implode(', ', array_map(fn (string $column): string => '`'.$column.'`', array_keys($row)));
        $values = implode(', ', array_map(fn (mixed $value): string => $this->quote($value), array_values($row)));

        return 'INSERT INTO '.$table.' ('.$columns.') VALUES ('.$values.');';
    }

    private function quote(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return DB::getPdo()->quote((string) $value);
    }
}
