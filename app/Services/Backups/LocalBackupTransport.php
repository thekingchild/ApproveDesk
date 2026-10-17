<?php

namespace App\Services\Backups;

use App\Contracts\BackupTransport;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class LocalBackupTransport implements BackupTransport
{
    public function put(string $name, string $absolutePath): string
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('Local backup file is missing.');
        }

        return $name;
    }

    public function has(string $remoteId): bool
    {
        return Storage::disk('local')->exists($remoteId);
    }

    public function fetch(string $remoteId, string $destination): void
    {
        if (! Storage::disk('local')->exists($remoteId) || ! copy(Storage::disk('local')->path($remoteId), $destination)) {
            throw new RuntimeException('Local backup file is missing.');
        }
    }

    public function delete(string $remoteId): void
    {
        Storage::disk('local')->delete($remoteId);
    }

    public function test(): bool
    {
        Storage::disk('local')->makeDirectory('backups');

        return Storage::disk('local')->directories('backups') !== [] || Storage::disk('local')->exists('backups');
    }
}
