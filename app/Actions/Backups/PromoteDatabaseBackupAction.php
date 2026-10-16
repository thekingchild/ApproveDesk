<?php

namespace App\Actions\Backups;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupDestination;
use App\Models\BackupSnapshot;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Services\Backups\BackupRecorder;
use App\Services\Backups\BackupRestoreTrail;
use App\Services\Backups\MysqlDumpLoader;
use App\Services\Backups\SqliteSnapshotLoader;
use App\Services\Operations\BackupVerifier;
use App\Services\Operations\OperationsReconciler;
use App\Support\LongRunningOperation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class PromoteDatabaseBackupAction
{
    /** Read by the fatal-error guard: once loading starts the live tables may be half replaced. */
    private bool $loadStarted = false;

    private ?int $safetySnapshotId = null;

    public function __construct(
        private readonly VerifyBackupSnapshotAction $verify,
        private readonly BackupRecorder $recorder,
        private readonly BackupRestoreTrail $restores,
        private readonly SqliteSnapshotLoader $sqliteLoader,
        private readonly BackupVerifier $verifier,
        private readonly OperationsReconciler $reconciler,
        private readonly CreateDatabaseBackupAction $createBackup,
        private readonly MysqlDumpLoader $loader,
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

        LongRunningOperation::prepare();
        $this->verify->execute($snapshot);
        $snapshot->loadMissing('destination');

        $driver = Schema::getConnection()->getDriverName();
        $liveActions = WorkflowAction::query()->count();
        $safety = null;

        if ($driver === 'sqlite') {
            $this->promoteSqliteFile($snapshot);
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $safety = $this->promoteSqlDump($snapshot);
        } else {
            throw new InvalidArgumentException('Promote supports SQLite files and MySQL.');
        }

        $snapshotActions = (int) ($snapshot->manifest['workflow_actions'] ?? $liveActions);
        // The settings table was replaced wholesale; drop the cached copy.
        Setting::flushCache();

        $this->rememberPromoted($snapshot, $actor, [
            'discarded_workflow_actions' => max(0, $liveActions - $snapshotActions),
            'safety_snapshot_id' => $safety?->id,
        ]);

        $this->reconciler->run();

        return BackupSnapshot::query()->find($snapshot->id) ?? $snapshot;
    }

    /**
     * @param  array{discarded_workflow_actions?: int, safety_snapshot_id?: int|null}  $details
     */
    public function rememberPromoted(BackupSnapshot $snapshot, User $actor, array $details = []): BackupSnapshot
    {
        $this->restoreDestinationIfMissing($snapshot);

        $attributes = [
            'kind' => $snapshot->kind,
            'destination_id' => $snapshot->destination_id,
            'status' => BackupSnapshotStatus::Promoted,
            'path' => $snapshot->path,
            'remote_id' => $snapshot->remote_id,
            'bytes' => $snapshot->bytes,
            'checksum' => $snapshot->checksum,
            'manifest' => $snapshot->manifest,
            'retained_until' => $snapshot->retained_until,
            'verified_at' => $snapshot->verified_at,
            'promoted_at' => now(),
            'error' => null,
        ];
        if (Schema::hasColumn('backup_snapshots', 'promoted_by_id')) {
            $attributes['promoted_by_id'] = $actor->id;
        }
        if (Schema::hasColumn('backup_snapshots', 'taken_at')) {
            $attributes['taken_at'] = $snapshot->taken_at ?? $snapshot->created_at;
        }

        $persisted = BackupSnapshot::query()->find($snapshot->id);
        if ($persisted === null) {
            $persisted = new BackupSnapshot;
            $persisted->id = $snapshot->id;
        }

        $persisted->forceFill($attributes)->save();
        $this->restores->record($persisted, $actor, $details);

        Log::info('backup.promoted', [
            'kind' => 'database',
            'snapshot_id' => $persisted->id,
            'actor_id' => $actor->id,
        ]);

        return $persisted;
    }

    private function restoreDestinationIfMissing(BackupSnapshot $snapshot): void
    {
        if (BackupDestination::query()->whereKey($snapshot->destination_id)->exists()) {
            return;
        }

        $destination = $snapshot->relationLoaded('destination')
            ? $snapshot->getRelation('destination')
            : null;

        if (! $destination instanceof BackupDestination) {
            throw new RuntimeException('The snapshot destination could not be restored.');
        }

        $restored = new BackupDestination;
        $restored->forceFill($destination->getAttributes());
        $restored->save();
    }

    private function promoteSqliteFile(BackupSnapshot $snapshot): void
    {
        $live = (string) config('database.connections.'.config('database.default').'.database');
        if ($live === '' || $live === ':memory:' || ! is_file($live)) {
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

        $aside = $live.'.pre-promote';
        Artisan::call('down', ['--retry' => 60]);
        // A fatal error skips finally; put the old file back and come up (RV-OPS-03).
        $guard = LongRunningOperation::onFatal(function () use ($live, $aside): void {
            if (is_file($aside)) {
                copy($aside, $live);
            }
            Artisan::call('up');
        });

        try {
            copy($live, $aside);
            if (! copy($scratch, $live)) {
                copy($aside, $live);
                throw new RuntimeException('Could not replace the live database.');
            }
        } finally {
            $guard->disarm();
            Artisan::call('up');
            if (is_file($scratch)) {
                unlink($scratch);
            }
        }
    }

    /**
     * MySQL/MariaDB cannot replace tables transactionally, so (RV-DATA-03):
     * enter maintenance mode, take and verify a fresh safety backup, load the
     * snapshot, verify it, and only then come back up. Any failure reloads the
     * safety backup; if that also fails the site stays in maintenance mode.
     */
    private function promoteSqlDump(BackupSnapshot $snapshot): BackupSnapshot
    {
        $sql = $this->decodeSqlDump($snapshot);
        $safetySql = null;
        $stayDown = false;
        $this->loadStarted = false;
        $this->safetySnapshotId = null;

        Artisan::call('down', ['--retry' => 60]);
        // A fatal error (out of memory, host time limit) skips finally. Before the
        // load starts the live tables are untouched, so come back up; once it has
        // started they may be half replaced, so stay down and say how to recover.
        $guard = LongRunningOperation::onFatal(function () use ($snapshot): void {
            if (! $this->loadStarted) {
                Artisan::call('up');

                return;
            }

            Log::critical('backup.promote_interrupted', [
                'snapshot_id' => $snapshot->id,
                'safety_snapshot_id' => $this->safetySnapshotId,
            ]);
        });

        try {
            $safety = $this->createBackup->execute();
            $this->verify->execute($safety);
            $safety = $safety->fresh() ?? $safety;
            $this->safetySnapshotId = $safety->id;
            $safetySql = $this->decodeSqlDump($safety);

            try {
                $this->loadStarted = true;
                $this->loader->replaceFromFile($sql);
                $this->assertRestored($snapshot);
            } catch (Throwable $failure) {
                try {
                    $this->loader->replaceFromFile($safetySql);
                    $this->reinsert($safety);
                } catch (Throwable $rollbackFailure) {
                    $stayDown = true;
                    Log::critical('backup.promote_rollback_failed', [
                        'snapshot_id' => $snapshot->id,
                        'safety_snapshot_id' => $safety->id,
                        'error' => $rollbackFailure->getMessage(),
                    ]);

                    throw new RuntimeException(
                        "Promote failed and the automatic rollback also failed. The site stays in maintenance mode; restore safety snapshot #{$safety->id}.",
                        previous: $failure,
                    );
                }

                Log::warning('backup.promote_rolled_back', [
                    'snapshot_id' => $snapshot->id,
                    'safety_snapshot_id' => $safety->id,
                    'error' => $failure->getMessage(),
                ]);

                throw $failure;
            }

            return $this->reinsert($safety);
        } finally {
            $guard->disarm();
            foreach ([$sql, $safetySql] as $decoded) {
                if ($decoded !== null && is_file($decoded)) {
                    unlink($decoded);
                }
            }
            if (! $stayDown) {
                Artisan::call('up');
            }
        }
    }

    private function assertRestored(BackupSnapshot $snapshot): void
    {
        $check = $this->verifier->verifyCurrent();
        if (! $check->ok) {
            throw new InvalidArgumentException('This snapshot failed the backup check.');
        }

        $expected = (int) ($snapshot->manifest['workflow_actions'] ?? -1);
        if ($expected >= 0 && ($check->tableCounts['workflow_actions'] ?? -1) !== $expected) {
            throw new InvalidArgumentException('workflow_actions count does not match the snapshot.');
        }
    }

    /** Decompresses the snapshot to a temporary .sql file; the caller deletes it. */
    private function decodeSqlDump(BackupSnapshot $snapshot): string
    {
        $absolute = app(MaterializeBackupSnapshotAction::class)->execute($snapshot);
        $decoded = storage_path('app/private/backups/.promote-'.uniqid('', true).'.sql');

        try {
            $this->recorder->gunzipTo($absolute, $decoded);
        } catch (Throwable $exception) {
            @unlink($decoded);

            throw $exception;
        }

        if ((int) filesize($decoded) === 0) {
            unlink($decoded);

            throw new InvalidArgumentException('The snapshot file is empty.');
        }

        return $decoded;
    }

    /**
     * The safety snapshot's row lives in the table that was just replaced;
     * write it back so the backup stays listed and restorable.
     */
    private function reinsert(BackupSnapshot $snapshot): BackupSnapshot
    {
        $existing = BackupSnapshot::query()->find($snapshot->id);
        if ($existing !== null) {
            return $existing;
        }

        // Raw attributes: re-filling casts (manifest JSON, enums) would double-encode them.
        $persisted = new BackupSnapshot;
        $persisted->setRawAttributes($snapshot->getAttributes());
        $persisted->save();

        return $persisted;
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

            $this->sqliteLoader->loadSqlDumpFile($decoded, $scratch);
        } finally {
            if (is_file($decoded)) {
                unlink($decoded);
            }
        }
    }
}
