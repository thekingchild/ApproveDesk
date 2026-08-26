<?php

namespace App\Services\Backups;

use InvalidArgumentException;
use PDO;
use PDOException;

final class SqliteSnapshotLoader
{
    public function loadSqlDump(string $sql, string $sqlitePath): void
    {
        $sql = $this->withoutMysqlSessionStatements($sql);
        if (trim($sql) === '') {
            throw new InvalidArgumentException('The snapshot file is empty.');
        }

        $pdo = new PDO('sqlite:'.$sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec('PRAGMA foreign_keys = OFF');

        try {
            $pdo->exec($sql);
        } catch (PDOException $exception) {
            throw new InvalidArgumentException('This snapshot could not be loaded as a SQLite database.');
        }
    }

    public function withoutMysqlSessionStatements(string $sql): string
    {
        $cleaned = preg_replace('/^\s*SET\s+FOREIGN_KEY_CHECKS\s*=\s*\d+\s*;\s*$/mi', '', $sql);

        return is_string($cleaned) ? $cleaned : $sql;
    }
}
