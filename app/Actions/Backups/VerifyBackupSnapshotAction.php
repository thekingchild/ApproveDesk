<?php

namespace App\Actions\Backups;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupRecorder;
use App\Services\Operations\BackupVerifier;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ZipArchive;

final class VerifyBackupSnapshotAction
{
    public function __construct(
        private readonly BackupVerifier $verifier,
        private readonly BackupRecorder $recorder,
    ) {}

    public function execute(BackupSnapshot $snapshot): BackupSnapshot
    {
        $absolute = $this->localPath($snapshot);
        $scratch = storage_path('app/private/backups/.verify-'.uniqid('', true));
        @mkdir(dirname($scratch), 0775, true);

        try {
            if (str_ends_with($absolute, '.gz')) {
                $this->recorder->gunzipTo($absolute, $scratch);
                $ok = $this->verifier->verifyPath($scratch)->ok;
            } elseif ($snapshot->kind === BackupSnapshotKind::Files) {
                $ok = $this->verifyZip($absolute, $snapshot->manifest ?? []);
            } else {
                $ok = $this->verifier->verifyPath($absolute)->ok;
            }
        } catch (\RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), 0, $exception);
        } finally {
            if (is_file($scratch)) {
                unlink($scratch);
            }
        }

        if (! $ok) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        $snapshot->forceFill([
            'status' => BackupSnapshotStatus::Verified,
            'verified_at' => now(),
        ])->save();

        return $snapshot->fresh() ?? $snapshot;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function verifyZip(string $path, array $manifest): bool
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return false;
        }

        $expected = $manifest['files'] ?? [];
        if (! is_array($expected)) {
            $zip->close();

            return false;
        }

        foreach ($expected as $file) {
            $name = is_array($file) ? (string) ($file['path'] ?? '') : '';
            if ($name === '' || $zip->locateName($name) === false) {
                $zip->close();

                return false;
            }
        }

        $zip->close();

        return true;
    }

    private function localPath(BackupSnapshot $snapshot): string
    {
        if (! is_string($snapshot->path) || ! Storage::disk('local')->exists($snapshot->path)) {
            throw new InvalidArgumentException('The snapshot file is not on this copy.');
        }

        return Storage::disk('local')->path($snapshot->path);
    }
}
