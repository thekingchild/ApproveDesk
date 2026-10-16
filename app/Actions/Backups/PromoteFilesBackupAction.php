<?php

namespace App\Actions\Backups;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use App\Models\BackupSnapshot;
use App\Models\User;
use App\Services\Backups\BackupIntegrity;
use App\Services\Backups\BackupRestoreTrail;
use App\Support\LongRunningOperation;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

final class PromoteFilesBackupAction
{
    public function __construct(
        private readonly VerifyBackupSnapshotAction $verify,
        private readonly BackupRestoreTrail $restores,
        private readonly BackupIntegrity $integrity,
    ) {}

    public function execute(BackupSnapshot $snapshot, User $actor, string $confirmation): BackupSnapshot
    {
        LongRunningOperation::prepare();
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

        $absolute = app(MaterializeBackupSnapshotAction::class)->execute($snapshot);
        $names = $this->integrity->verifiedZipEntryNames($absolute, $snapshot->manifestArray());

        $zip = new ZipArchive;
        if ($zip->open($absolute) !== true) {
            throw new InvalidArgumentException('The files snapshot could not be opened.');
        }

        try {
            $root = Storage::disk('local')->path($holding);
            foreach ($names as $name) {
                $this->extractEntry($zip, $root, $name);
            }
        } finally {
            $zip->close();
        }

        $stamp = now()->format('YmdHis');
        $local = Storage::disk('local');
        $this->swapIn(
            $local->path($holding.'/submissions'),
            $local->path('submissions'),
            $local->path('submissions-previous-'.$stamp),
            replaceWhenMissing: true,
        );
        // Branding artwork is part of the snapshot and lives on the public disk
        // (RV-BUG-23). The artwork it replaces is set aside on the private disk
        // so it is not web-reachable. A snapshot without artwork keeps today's.
        $this->swapIn(
            $local->path($holding.'/branding'),
            Storage::disk('public')->path('branding'),
            $local->path('branding-previous-'.$stamp),
            replaceWhenMissing: false,
        );
        $local->deleteDirectory($holding);

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

    /**
     * Move the live folder aside, then move the restored copy into place. The
     * previous tree is only moved, never deleted (BK-RST-03).
     */
    private function swapIn(string $restored, string $live, string $previous, bool $replaceWhenMissing): void
    {
        if (! is_dir($restored) && ! $replaceWhenMissing) {
            return;
        }

        if (is_dir($live)) {
            $this->moveDirectory($live, $previous);
        }

        if (is_dir($restored)) {
            $parent = dirname($live);
            if (! is_dir($parent) && ! mkdir($parent, 0775, true) && ! is_dir($parent)) {
                throw new RuntimeException('The restored files could not be put in place.');
            }

            $this->moveDirectory($restored, $live);
        }
    }

    private function moveDirectory(string $from, string $to): void
    {
        if (@rename($from, $to)) {
            return;
        }

        // The public and private disks can sit on different filesystems.
        if (! File::copyDirectory($from, $to)) {
            throw new RuntimeException('The restored files could not be put in place.');
        }

        File::deleteDirectory($from);
    }

    private function extractEntry(ZipArchive $zip, string $root, string $name): void
    {
        $target = $this->safeTarget($root, $name);
        $directory = dirname($target);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new InvalidArgumentException('The files snapshot could not be opened.');
        }

        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new InvalidArgumentException('The files snapshot could not be opened.');
        }

        $out = fopen($target, 'wb');
        if ($out === false) {
            fclose($stream);
            throw new InvalidArgumentException('The files snapshot could not be opened.');
        }

        try {
            stream_copy_to_stream($stream, $out);
        } finally {
            fclose($stream);
            fclose($out);
        }

        $realRoot = realpath($root);
        $realTarget = realpath($target);
        if (
            $realRoot === false
            || $realTarget === false
            || ($realTarget !== $realRoot && ! str_starts_with($realTarget, $realRoot.DIRECTORY_SEPARATOR))
        ) {
            @unlink($target);
            throw new InvalidArgumentException('The files snapshot could not be opened.');
        }
    }

    private function safeTarget(string $root, string $name): string
    {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $name)) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException('The files snapshot could not be opened.');
            }
            $parts[] = $part;
        }

        return $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
    }
}
