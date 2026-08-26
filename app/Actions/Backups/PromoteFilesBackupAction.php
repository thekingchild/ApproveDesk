<?php

namespace App\Actions\Backups;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupSnapshot;
use App\Models\User;
use App\Services\Backups\BackupRestoreTrail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use ZipArchive;

final class PromoteFilesBackupAction
{
    public function __construct(
        private readonly VerifyBackupSnapshotAction $verify,
        private readonly BackupRestoreTrail $restores,
    ) {}

    public function execute(BackupSnapshot $snapshot, User $actor, string $confirmation): BackupSnapshot
    {
        if ($snapshot->kind !== BackupSnapshotKind::Files) {
            throw new InvalidArgumentException('Only a files snapshot can be promoted here.');
        }

        if (trim($confirmation) !== (string) $snapshot->id) {
            throw new InvalidArgumentException('Type the snapshot number to confirm.');
        }

        if (! $snapshot->isVerified()) {
            throw new InvalidArgumentException('Verify the snapshot before promoting it.');
        }

        $this->verify->execute($snapshot);

        $holding = 'restore-holding/'.now()->format('YmdHis');
        Storage::disk('local')->makeDirectory($holding);

        $zip = new ZipArchive;
        $absolute = app(MaterializeBackupSnapshotAction::class)->execute($snapshot);
        if ($zip->open($absolute) !== true) {
            throw new InvalidArgumentException('The files snapshot could not be opened.');
        }

        $zip->extractTo(Storage::disk('local')->path($holding));
        $zip->close();

        $previous = 'submissions-previous-'.now()->format('YmdHis');
        if (Storage::disk('local')->exists('submissions')) {
            rename(Storage::disk('local')->path('submissions'), Storage::disk('local')->path($previous));
        }

        $extracted = Storage::disk('local')->path($holding.'/submissions');
        if (is_dir($extracted)) {
            rename($extracted, Storage::disk('local')->path('submissions'));
        }

        $attributes = [
            'status' => BackupSnapshotStatus::Promoted,
            'promoted_at' => now(),
        ];
        if (Schema::hasColumn('backup_snapshots', 'promoted_by_id')) {
            $attributes['promoted_by_id'] = $actor->id;
        }
        $snapshot->forceFill($attributes)->save();
        $this->restores->record($snapshot, $actor);

        Log::info('backup.promoted', [
            'kind' => 'files',
            'snapshot_id' => $snapshot->id,
            'actor_id' => $actor->id,
        ]);

        return $snapshot->fresh() ?? $snapshot;
    }
}
