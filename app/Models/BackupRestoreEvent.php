<?php

namespace App\Models;

use App\Enums\BackupSnapshotKind;
use Database\Factories\BackupRestoreEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['snapshot_id', 'actor_id', 'actor_name', 'kind', 'snapshot_name', 'discarded_workflow_actions', 'safety_snapshot_id'])]
class BackupRestoreEvent extends Model
{
    /** @use HasFactory<BackupRestoreEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'kind' => BackupSnapshotKind::class,
            'discarded_workflow_actions' => 'integer',
            'safety_snapshot_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('backup_restore_events is append-only; updates are forbidden.');
        });
        static::deleting(function (): never {
            throw new LogicException('backup_restore_events is append-only; deletes are forbidden.');
        });
    }

    /** @return BelongsTo<BackupSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(BackupSnapshot::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
