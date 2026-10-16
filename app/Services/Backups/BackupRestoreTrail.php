<?php

namespace App\Services\Backups;

use App\Models\BackupRestoreEvent;
use App\Models\BackupSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class BackupRestoreTrail
{
    /**
     * @param  array{discarded_workflow_actions?: int, safety_snapshot_id?: int|null}  $details
     */
    public function record(BackupSnapshot $snapshot, User $actor, array $details = []): void
    {
        if (! Schema::hasTable('backup_restore_events')) {
            return;
        }

        $attributes = [
            'snapshot_id' => $snapshot->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'kind' => $snapshot->kind,
            'snapshot_name' => $snapshot->displayName(),
        ];

        // Columns added in v2.8.7 (RV-DATA-03); older schemas simply omit them.
        if ($details !== [] && Schema::hasColumn('backup_restore_events', 'discarded_workflow_actions')) {
            $attributes['discarded_workflow_actions'] = $details['discarded_workflow_actions'] ?? null;
            $attributes['safety_snapshot_id'] = $details['safety_snapshot_id'] ?? null;
        }

        BackupRestoreEvent::query()->create($attributes);
    }
}
