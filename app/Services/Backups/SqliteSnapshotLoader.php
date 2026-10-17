<?php

namespace App\Services\Backups;

use App\Support\SqlStatementStream;
use InvalidArgumentException;
use PDO;
use PDOException;

final class SqliteSnapshotLoader
{
    /** Loads a dump file into a new SQLite database one statement at a time (RV-OPS-03). */
    public function loadSqlDumpFile(string $dumpPath, string $sqlitePath): void
    {
        $pdo = new PDO('sqlite:'.$sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $loaded = 0;

        try {
            foreach (SqlStatementStream::statements($dumpPath) as $statement) {
                $statement = trim($this->withoutMysqlSessionStatements($statement));
                if ($statement === '' || $statement === ';') {
                    continue;
                }

                $pdo->exec($statement);
                $loaded++;
            }
        } catch (PDOException) {
            throw new InvalidArgumentException('This snapshot could not be loaded as a SQLite database.');
        }

        if ($loaded === 0) {
            throw new InvalidArgumentException('The snapshot file is empty.');
        }
    }

    public function withoutMysqlSessionStatements(string $sql): string
    {
        $cleaned = preg_replace('/^\s*SET\s+FOREIGN_KEY_CHECKS\s*=\s*\d+\s*;\s*$/mi', '', $sql);

        return is_string($cleaned) ? $cleaned : $sql;
    }
}
