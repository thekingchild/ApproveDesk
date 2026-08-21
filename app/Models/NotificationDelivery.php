<?php

namespace App\Models;

use App\Enums\NotificationDeliveryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $channel
 * @property NotificationDeliveryStatus $status
 * @property int $attempts
 * @property string|null $last_error
 * @property array<string, mixed>|null $payload
 */
class NotificationDelivery extends Model
{
    protected $fillable = [
        'user_id', 'kind', 'channel', 'status', 'attempts', 'last_error', 'payload', 'sent_at', 'next_retry_at',
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
