<?php

namespace App\Actions\Backups;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupSnapshot;
use App\Services\Backups\BackupIntegrity;
use App\Services\Backups\BackupRecorder;
use App\Services\Operations\BackupVerifier;
use App\Support\LongRunningOperation;
use InvalidArgumentException;

final class VerifyBackupSnapshotAction
{
    public function __construct(
        private readonly BackupVerifier $verifier,
        private readonly BackupRecorder $recorder,
        private readonly BackupIntegrity $integrity,
    ) {}

    public function execute(BackupSnapshot $snapshot): BackupSnapshot
    {
        LongRunningOperation::prepare();
        $absolute = app(MaterializeBackupSnapshotAction::class)->execute($snapshot);
        $scratch = storage_path('app/private/backups/.verify-'.uniqid('', true));
        @mkdir(dirname($scratch), 0775, true);

        try {
            if (str_ends_with($absolute, '.gz')) {
                $this->recorder->gunzipTo($absolute, $scratch);
                $ok = $this->verifier->verifyPath($scratch)->ok;
            } elseif ($snapshot->kind === BackupSnapshotKind::Files) {
                $this->integrity->assertZipMatchesManifest($absolute, $snapshot->manifestArray());
                $ok = true;
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
}
