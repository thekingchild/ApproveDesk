<?php

namespace App\Models;

use App\Enums\NotificationDeliveryStatus;
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
