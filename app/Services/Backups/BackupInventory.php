<?php

namespace App\Services\Backups;

use App\Enums\BackupDestinationKind;
use App\Enums\BackupSnapshotKind;
use App\Models\BackupSnapshot;
use App\Models\WorkflowAction;
use Illuminate\Support\Facades\DB;

/**
 * Read-only answers about stored snapshots: the space they use, which local
 * files also have a remote copy, and how much workflow history a database
 * promote would remove (RV-ARC-09). The Backups screens and
 * DeleteLocalBackupCopyAction ask here instead of each keeping its own query.
 */
final class BackupInventory
{
    /** Bytes held by successful snapshots of one kind, counting each stored file once. */
    public function storedBytes(BackupSnapshotKind $kind): int
    {
        $perPath = BackupSnapshot::query()
            ->where('kind', $kind)
            ->successful()
            ->groupBy('path')
            ->select(['path', DB::raw('MAX(bytes) as bytes')]);

        return (int) DB::query()->fromSub($perPath, 'stored')->sum('bytes');
    }

    /**
     * The given paths that also have a successful copy on a remote destination.
     *
     * @param  iterable<int, string|null>  $paths
     * @return list<string>
     */
    public function remotelyCopiedPaths(iterable $paths): array
    {
        $paths = collect($paths)->filter()->unique()->values()->all();

        if ($paths === []) {
            return [];
        }

        return array_values(BackupSnapshot::query()
            ->whereIn('path', $paths)
            ->successful()
            ->whereHas('destination', fn ($query) => $query->where('kind', '!=', BackupDestinationKind::Local->value))
            ->distinct()
            ->pluck('path')
            ->map(fn ($path): string => (string) $path)
            ->all());
    }

    /** Whether a local snapshot's file also exists, successfully, on a remote destination. */
    public function hasRemoteReplica(BackupSnapshot $snapshot): bool
    {
        return BackupSnapshot::query()
            ->where('path', $snapshot->path)
            ->where('id', '!=', $snapshot->id)
            ->successful()
            ->whereHas('destination', fn ($query) => $query->where('kind', '!=', BackupDestinationKind::Local->value))
            ->exists();
    }

    /**
     * Workflow actions recorded after each database snapshot: a promote removes
     * them from the live history. Reads the small manifests only (RV-PERF-17).
     *
     * @param  iterable<int, int>  $snapshotIds
     * @return array<int, int> keyed by snapshot id
     */
    public function workflowActionsLostBy(iterable $snapshotIds): array
    {
        $ids = collect($snapshotIds)->values()->all();

        if ($ids === []) {
            return [];
        }

        $recorded = BackupSnapshot::query()
            ->whereIn('id', $ids)
            ->where('kind', BackupSnapshotKind::Database)
            ->get(['id', 'manifest']);

        if ($recorded->isEmpty()) {
            return [];
        }

        $live = $this->liveWorkflowActions();

        return $recorded
            ->mapWithKeys(fn (BackupSnapshot $snapshot): array => [
                $snapshot->id => max(0, $live - (int) ($snapshot->manifestArray()['workflow_actions'] ?? 0)),
            ])
            ->all();
    }

    /**
     * The count a database snapshot records in its manifest (BackupRecorder),
     * so the two numbers compare like for like.
     */
    private function liveWorkflowActions(): int
    {
        return WorkflowAction::query()->count();
    }
}
