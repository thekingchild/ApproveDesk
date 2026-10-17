<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $endpoint_id
 * @property string $event_id
 * @property WebhookEvent $event
 * @property array<string, mixed> $payload
 * @property WebhookDeliveryStatus $status
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $last_error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $next_attempt_at
 * @property-read WebhookEndpoint $endpoint
 */
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'endpoint_id', 'event_id', 'event', 'payload', 'status', 'attempts',
        'response_status', 'response_body', 'last_error', 'delivered_at', 'next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => WebhookDeliveryStatus::class,
            'event' => WebhookEvent::class,
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'endpoint_id');
    }
}
