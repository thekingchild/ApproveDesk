<?php

namespace App\Models;

use Database\Factories\OpsSnapshotFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $recorded_at
 * @property int $pending_jobs
 * @property int $failed_jobs
 * @property int $dead_letter_webhooks
 * @property int $dead_letter_integrations
 * @property int $failed_notifications
 * @property int $stuck_workflows
 * @property int|null $oldest_pending_seconds
 */
class OpsSnapshot extends Model
{
    /** @use HasFactory<OpsSnapshotFactory> */
    use HasFactory;

    use MassPrunable;

    /** Snapshots are written every minute; keep two weeks (RV-OPS-04). */
    public const KEEP_DAYS = 14;

    public $timestamps = false;

    protected $fillable = [
        'recorded_at',
        'pending_jobs',
        'failed_jobs',
        'dead_letter_webhooks',
        'dead_letter_integrations',
        'failed_notifications',
        'stuck_workflows',
        'oldest_pending_seconds',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'pending_jobs' => 'integer',
            'failed_jobs' => 'integer',
            'dead_letter_webhooks' => 'integer',
            'dead_letter_integrations' => 'integer',
            'failed_notifications' => 'integer',
            'stuck_workflows' => 'integer',
            'oldest_pending_seconds' => 'integer',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('recorded_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
