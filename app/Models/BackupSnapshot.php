<?php

namespace App\Models;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use Database\Factories\BackupSnapshotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupSnapshot extends Model
{
    /** @use HasFactory<BackupSnapshotFactory> */
    use HasFactory;

    protected $fillable = [
        'kind',
        'destination_id',
        'status',
        'path',
        'remote_id',
        'bytes',
        'checksum',
        'manifest',
        'error',
        'retained_until',
        'verified_at',
        'promoted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BackupSnapshotKind::class,
            'status' => BackupSnapshotStatus::class,
            'manifest' => 'array',
            'retained_until' => 'datetime',
            'verified_at' => 'datetime',
            'promoted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereIn('status', [
            BackupSnapshotStatus::Success->value,
            BackupSnapshotStatus::Verified->value,
            BackupSnapshotStatus::Promoted->value,
        ]);
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, [
            BackupSnapshotStatus::Success,
            BackupSnapshotStatus::Verified,
            BackupSnapshotStatus::Promoted,
        ], true);
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null
            && in_array($this->status, [BackupSnapshotStatus::Verified, BackupSnapshotStatus::Promoted], true);
    }
}
