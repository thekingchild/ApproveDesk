<?php

namespace App\Services\Backups;

use App\Models\BackupRestoreEvent;
use App\Models\BackupSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

final class BackupRestoreTrail
{
    public function record(BackupSnapshot $snapshot, User $actor): void
    {
        if (! Schema::hasTable('backup_restore_events')) {
            return;
        }

        BackupRestoreEvent::query()->create([
            'snapshot_id' => $snapshot->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'kind' => $snapshot->kind,
            'snapshot_name' => $snapshot->displayName(),
        ]);
    }
}
