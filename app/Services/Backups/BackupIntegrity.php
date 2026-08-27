<?php

namespace App\Services\Backups;

use App\Models\BackupSnapshot;
use InvalidArgumentException;
use ZipArchive;

final class BackupIntegrity
{
    public const MAX_ZIP_ENTRIES = 10_000;

    public const MAX_ZIP_UNCOMPRESSED_BYTES = 2_147_483_647;

    public function assertMatches(BackupSnapshot $snapshot, string $absolute): void
    {
        if (! is_file($absolute) || ! is_readable($absolute)) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        $checksum = (string) $snapshot->checksum;
        if ($checksum === '') {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        $expectedBytes = (int) $snapshot->bytes;
        $size = filesize($absolute);
        if ($size === false || $expectedBytes <= 0 || $size !== $expectedBytes) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        $actual = hash_file('sha256', $absolute);
        if (! is_string($actual) || ! hash_equals($checksum, $actual)) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function assertZipMatchesManifest(string $path, array $manifest): void
    {
        $this->verifiedZipEntryNames($path, $manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public function verifiedZipEntryNames(string $path, array $manifest): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        try {
            return $this->inspectZip($zip, $manifest);
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function inspectZip(ZipArchive $zip, array $manifest): array
    {
        $files = $manifest['files'] ?? [];
        if (! is_array($files)) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        $expectedHashes = [];
        foreach ($files as $file) {
            if (! is_array($file)) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }

            $name = (string) ($file['path'] ?? '');
            $hash = (string) ($file['sha256'] ?? '');
            if ($name === '' || $hash === '' || isset($expectedHashes[$name])) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }

            $this->assertSafeZipName($name);
            $expectedHashes[$name] = $hash;
        }

        $expectedSet = $expectedHashes;
        $expectedSet['manifest.json'] = true;

        if ($zip->numFiles > self::MAX_ZIP_ENTRIES || $zip->numFiles < 1) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        $seen = [];
        $uncompressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }

            $name = $stat['name'];
            if ($name === '' || isset($seen[$name])) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }
            $seen[$name] = true;

            if (! array_key_exists($name, $expectedSet)) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }

            $this->assertSafeZipName($name);
            $this->assertRegularFileEntry($zip, $i, $name);

            $size = $stat['size'];
            if ($size < 0) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }
            $uncompressed += $size;
            if ($uncompressed > self::MAX_ZIP_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }

            if ($name === 'manifest.json') {
                continue;
            }

            $actual = $this->hashZipEntry($zip, $name);
            if (! hash_equals($expectedHashes[$name], $actual)) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }
        }

        if (count($seen) !== count($expectedSet)) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        return array_keys($expectedHashes);
    }

    private function assertSafeZipName(string $name): void
    {
        if (str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }
        }
    }

    private function assertRegularFileEntry(ZipArchive $zip, int $index, string $name): void
    {
        if (str_ends_with($name, '/')) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        $opsys = 0;
        $attr = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attr) === true && $opsys === ZipArchive::OPSYS_UNIX) {
            $mode = ($attr >> 16) & 0xFFFF;
            $type = $mode & 0170000;
            if ($type === 0120000 || $type === 0040000) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }
        }
    }

    private function hashZipEntry(ZipArchive $zip, string $name): string
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        try {
            $context = hash_init('sha256');
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false) {
                    throw new InvalidArgumentException('This snapshot failed the backup check.');
                }
                if ($chunk !== '') {
                    hash_update($context, $chunk);
                }
            }

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
