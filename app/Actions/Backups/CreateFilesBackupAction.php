<?php

namespace App\Actions\Backups;

use App\Enums\BackupSnapshotKind;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupRecorder;
use App\Support\BackupSettings;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

final class CreateFilesBackupAction
{
    public function __construct(private readonly BackupRecorder $recorder) {}

    public function execute(): BackupSnapshot
    {
        $zipPath = storage_path('app/private/backups/.tmp-files-'.uniqid('', true).'.zip');
        @mkdir(dirname($zipPath), 0775, true);

        try {
            $manifest = $this->writeZip($zipPath);

            return $this->recorder->store(
                BackupSnapshotKind::Files,
                $zipPath,
                $manifest,
                BackupSettings::filesRetainDays(),
            );
        } finally {
            if (is_file($zipPath)) {
                unlink($zipPath);
            }
        }
    }

    /**
     * @return array{files: list<array{path: string, sha256: string}>, count: int}
     */
    private function writeZip(string $zipPath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create files backup.');
        }

        $entries = [];
        foreach ($this->files() as $absolute => $relative) {
            $zip->addFile($absolute, $relative);
            $entries[] = [
                'path' => $relative,
                'sha256' => hash_file('sha256', $absolute) ?: '',
            ];
        }

        $zip->addFromString('manifest.json', (string) json_encode(['files' => $entries], JSON_THROW_ON_ERROR));
        $zip->close();

        return ['files' => $entries, 'count' => count($entries)];
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        $found = [];

        foreach (Storage::disk('local')->allFiles('submissions') as $path) {
            if (str_starts_with($path, 'backups/')) {
                continue;
            }
            $found[Storage::disk('local')->path($path)] = $path;
        }

        if (is_dir(storage_path('app/public/branding'))) {
            foreach (Storage::disk('public')->allFiles('branding') as $path) {
                $found[Storage::disk('public')->path($path)] = $path;
            }
        }

        return $found;
    }
}
