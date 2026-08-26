<?php

namespace App\Actions\Backups;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupDestination;
use App\Models\BackupSnapshot;
use App\Models\User;
use App\Services\Backups\BackupRecorder;
use App\Services\Backups\BackupRestoreTrail;
use App\Services\Operations\BackupVerifier;
use App\Services\Operations\OperationsReconciler;
use App\Support\SchemaTable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

final class PromoteDatabaseBackupAction
{
    public function __construct(
        private readonly VerifyBackupSnapshotAction $verify,
        private readonly BackupRecorder $recorder,
        private readonly BackupRestoreTrail $restores,
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
        $snapshot->loadMissing('destination');

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->promoteSqliteFile($snapshot);
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->promoteSqlDump($snapshot);
        } else {
            throw new InvalidArgumentException('Promote supports SQLite files and MySQL.');
        }

        $this->rememberPromoted($snapshot, $actor);

        $this->reconciler->run();

        return BackupSnapshot::query()->find($snapshot->id) ?? $snapshot;
    }

    public function rememberPromoted(BackupSnapshot $snapshot, User $actor): BackupSnapshot
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
        $this->restores->record($persisted, $actor);

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
    }

    private function promoteSqlDump(BackupSnapshot $snapshot): void
    {
        $absolute = app(MaterializeBackupSnapshotAction::class)->execute($snapshot);
        $decoded = storage_path('app/private/backups/.promote-'.uniqid('', true).'.sql');
        $this->recorder->gunzipTo($absolute, $decoded);

        try {
            $sql = (string) file_get_contents($decoded);
            if ($sql === '') {
                throw new InvalidArgumentException('The snapshot file is empty.');
            }

            Artisan::call('down', ['--retry' => 60]);
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                foreach ($this->droppableTables() as $table) {
                    Schema::dropIfExists($table);
                }
                DB::unprepared($sql);
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } finally {
                Artisan::call('up');
            }

            $check = $this->verifier->verifyCurrent();
            if (! $check->ok) {
                throw new InvalidArgumentException('This snapshot failed the backup check.');
            }

            $expected = (int) ($snapshot->manifest['workflow_actions'] ?? -1);
            if ($expected >= 0 && ($check->tableCounts['workflow_actions'] ?? -1) !== $expected) {
                throw new InvalidArgumentException('workflow_actions count does not match the snapshot.');
            }
        } finally {
            if (is_file($decoded)) {
                unlink($decoded);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function droppableTables(): array
    {
        return SchemaTable::applicationTables();
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
