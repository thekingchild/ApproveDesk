<?php

namespace App\Models;

use App\Enums\BackupSnapshotKind;
use App\Enums\BackupSnapshotStatus;
use Carbon\CarbonInterface;
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
        'promoted_by_id',
        'taken_at',
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
            'taken_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BackupDestination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class);
    }

    /** @return BelongsTo<User, $this> */
    public function promoter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promoted_by_id');
    }

    public function takenAt(): CarbonInterface
    {
        return $this->taken_at ?? $this->created_at ?? now();
    }

    public function displayName(): string
    {
        return $this->takenAt()
            ->timezone((string) config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i:s');
    }

    public function downloadName(): string
    {
        $stamp = $this->takenAt()
            ->timezone((string) config('app.timezone', 'UTC'))
            ->format('Y-m-d-His');
        $extension = pathinfo((string) $this->path, PATHINFO_EXTENSION) ?: 'bin';

        return $this->kind->value.'-'.$stamp.'.'.$extension;
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

    public static function formatBytes(?int $bytes): string
    {
        $bytes = max(0, (int) $bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        if ($index === 0) {
            return $bytes.' B';
        }

        $formatted = rtrim(rtrim(sprintf('%.1f', $value), '0'), '.');

        return $formatted.' '.$units[$index];
    }
}
