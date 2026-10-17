<?php

namespace App\Models;

use App\Enums\NotificationDeliveryStatus;
use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $submission_id
 * @property string $kind
 * @property string $channel
 * @property NotificationDeliveryStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property array<string, mixed>|null $payload
 * @property Carbon|null $sent_at
 * @property Carbon|null $next_retry_at
 * @property Carbon|null $created_at
 */
class NotificationDelivery extends Model
{
    /** @use HasFactory<NotificationDeliveryFactory> */
    use HasFactory;

    use MassPrunable;

    /** Delivery attempts are kept this long, then pruned (RV-OPS-05). */
    public const KEEP_DAYS = 180;

    protected $fillable = [
        'user_id', 'submission_id', 'kind', 'channel', 'status', 'attempts', 'last_error', 'payload', 'sent_at', 'next_retry_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => NotificationDeliveryStatus::class,
            'attempts' => 'integer',
            'payload' => 'array',
            'sent_at' => 'datetime',
            'next_retry_at' => 'datetime',
        ];
    }

    /**
     * Deliveries about a request under an active legal hold are kept: they are
     * part of that request's evidence pack.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('created_at', '<', now()->subDays(self::KEEP_DAYS))
            ->where(fn (Builder $query) => $query
                ->whereNull('submission_id')
                ->orWhereNotIn('submission_id', LegalHold::query()->active()->select('submission_id')));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
