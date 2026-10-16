<?php

namespace App\Services\Operations;

use App\DTOs\Operations\BackupVerificationResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PDO;

final class BackupVerifier
{
    /** @var list<string> */
    public const REQUIRED_TABLES = [
        'users',
        'submissions',
        'workflow_instances',
        'workflow_actions',
        'settings',
        'jobs',
        'failed_jobs',
        'submission_files',
    ];

    public function verifyCurrent(): BackupVerificationResult
    {
        return $this->inspectConnection(null, 'current');
    }

    public function verifyPath(string $path): BackupVerificationResult
    {
        if (! is_file($path) || ! is_readable($path)) {
            return new BackupVerificationResult(false, 'file', $path, [], ['The backup file is missing or unreadable.']);
        }

        $header = (string) file_get_contents($path, false, null, 0, 16);

        if (str_starts_with($header, 'SQLite format 3')) {
            return $this->inspectSqliteFile($path);
        }

        return $this->inspectSqlDump($path);
    }

    private function inspectSqliteFile(string $path): BackupVerificationResult
    {
        $name = 'backup_verify_'.substr(hash('sha256', $path), 0, 12);
        config(['database.connections.'.$name => [
            'driver' => 'sqlite',
            'database' => $path,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge($name);

        try {
            return $this->inspectConnection($name, 'file', $path);
        } finally {
            DB::purge($name);
        }
    }

    private function inspectSqlDump(string $path): BackupVerificationResult
    {
        // Scan line by line; CREATE TABLE headers are on one line (RV-OPS-03).
        $created = [];
        $handle = fopen($path, 'rb');
        if ($handle !== false) {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^\s*create\s+table\s+(?:if\s+not\s+exists\s+)?[`"\[]?([A-Za-z0-9_]+)[`"\]]?/i', $line, $match) === 1) {
                    $created[strtolower($match[1])] = true;
                }
            }
            fclose($handle);
        }

        $counts = [];
        $issues = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $found = isset($created[$table]);
            $counts[$table] = $found ? 0 : -1;

            if (! $found) {
                $issues[] = 'Missing required table: '.$table.'.';
            }
        }

        return new BackupVerificationResult($issues === [], 'file', $path, $counts, $issues);
    }

    private function inspectConnection(?string $connection, string $source, ?string $path = null): BackupVerificationResult
    {
        $counts = [];
        $issues = [];

        foreach (self::REQUIRED_TABLES as $table) {
            $exists = $connection === null
                ? Schema::hasTable($table)
                : Schema::connection($connection)->hasTable($table);

            if (! $exists) {
                $counts[$table] = -1;
                $issues[] = 'Missing required table: '.$table.'.';

                continue;
            }

            $counts[$table] = $connection === null
                ? (int) DB::table($table)->count()
                : (int) DB::connection($connection)->table($table)->count();
        }

        return new BackupVerificationResult($issues === [], $source, $path, $counts, $issues);
    }

    public function sqliteHeaderValid(string $path): bool
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException('Backup file not found.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 16);
        fclose($handle);

        return is_string($header) && str_starts_with($header, 'SQLite format 3');
    }

    public function openReadonly(string $path): PDO
    {
        return new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
