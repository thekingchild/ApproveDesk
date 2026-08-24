<?php

namespace App\Actions\Backups;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupSnapshot;
use App\Models\User;
use App\Services\Backups\BackupRecorder;
use App\Services\Operations\BackupVerifier;
use App\Services\Operations\OperationsReconciler;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

final class PromoteDatabaseBackupAction
{
    public function __construct(
        private readonly VerifyBackupSnapshotAction $verify,
        private readonly BackupRecorder $recorder,
        private readonly BackupVerifier $verifier,
        private readonly OperationsReconciler $reconciler,
    ) {}

    public function execute(BackupSnapshot $snapshot, User $actor, string $confirmation): BackupSnapshot
    {
        if ($snapshot->kind !== BackupSnapshotKind::Database) {
            throw new InvalidArgumentException('Only a database snapshot can be promoted here.');
        }

        if (trim($confirmation) !== (string) $snapshot->id) {
            throw new InvalidArgumentException('Type the snapshot number to confirm.');
        }

        if (! $snapshot->isVerified()) {
            throw new InvalidArgumentException('Verify the snapshot before promoting it.');
        }

        $this->verify->execute($snapshot);

        $live = (string) config('database.connections.'.config('database.default').'.database');
        if (config('database.default') !== 'sqlite' || $live === '' || $live === ':memory:' || ! is_file($live)) {
            throw new InvalidArgumentException('Promote needs a file-backed database.');
        }

        $scratch = storage_path('app/private/backups/.promote-'.uniqid('', true).'.sqlite');
        $this->materializeSqlite($snapshot, $scratch);

        $check = $this->verifier->verifyPath($scratch);
        if (! $check->ok) {
            @unlink($scratch);
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        $expected = (int) ($snapshot->manifest['workflow_actions'] ?? -1);
        if ($expected >= 0 && ($check->tableCounts['workflow_actions'] ?? -1) !== $expected) {
            @unlink($scratch);
            throw new InvalidArgumentException('workflow_actions count does not match the snapshot.');
        }

        Artisan::call('down', ['--retry' => 60]);
        try {
            $aside = $live.'.pre-promote';
            copy($live, $aside);
            if (! copy($scratch, $live)) {
                copy($aside, $live);
                throw new RuntimeException('Could not replace the live database.');
            }
        } finally {
            Artisan::call('up');
            if (is_file($scratch)) {
                unlink($scratch);
            }
        }

        $snapshot->forceFill([
            'status' => BackupSnapshotStatus::Promoted,
            'promoted_at' => now(),
        ])->save();

        Log::info('backup.promoted', [
            'kind' => 'database',
            'snapshot_id' => $snapshot->id,
            'actor_id' => $actor->id,
        ]);

        $this->reconciler->run();

        return $snapshot->fresh() ?? $snapshot;
    }

    private function materializeSqlite(BackupSnapshot $snapshot, string $scratch): void
    {
        $absolute = app(MaterializeBackupSnapshotAction::class)->execute($snapshot);
        $decoded = storage_path('app/private/backups/.decode-'.uniqid('', true).'.sql');

        try {
            $this->recorder->gunzipTo($absolute, $decoded);
            $header = (string) file_get_contents($decoded, false, null, 0, 16);
            if (str_starts_with($header, 'SQLite format 3')) {
                copy($decoded, $scratch);

                return;
            }

            $pdo = new \PDO('sqlite:'.$scratch);
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec((string) file_get_contents($decoded));
        } finally {
            if (is_file($decoded)) {
                unlink($decoded);
            }
        }
    }
}
