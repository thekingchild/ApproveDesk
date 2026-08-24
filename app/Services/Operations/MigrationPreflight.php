<?php

namespace App\Services\Operations;

final class MigrationPreflight
{
    /**
     * @return list<string>
     */
    public function inspect(string $source): array
    {
        $flags = [];

        if (preg_match('/->dropColumn\s*\(|\bdrop\s+column\b|\bdrop\s+table\b/i', $source) === 1) {
            $flags[] = 'destructive_drop';
        }

        if (preg_match('/->renameColumn\s*\(|->rename\s*\(|\brename\s+(column|table)\b/i', $source) === 1) {
            $flags[] = 'rename';
        }

        if (preg_match('/->dropConstrainedForeignId\s*\(/', $source) === 1) {
            $flags[] = 'destructive_drop';
        }

        return array_values(array_unique($flags));
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, list<string>>
     */
    public function inspectFiles(array $paths): array
    {
        $flagged = [];

        foreach ($paths as $path) {
            if (! is_file($path)) {
                continue;
            }

            $flags = $this->inspect((string) file_get_contents($path));
            if ($flags !== []) {
                $flagged[basename($path)] = $flags;
            }
        }

        return $flagged;
    }

    /**
     * @return array<string, list<string>>
     */
    public function inspectPending(): array
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(database_path('migrations'));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];
        $pending = array_diff(array_keys($files), $ran);

        return $this->inspectFiles(array_map(
            fn (string $name): string => $files[$name],
            $pending,
        ));
    }
}
