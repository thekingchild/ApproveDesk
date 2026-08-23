<?php

namespace App\Models;

use App\Enums\IntegrationDeliveryStatus;
use Database\Factories\IntegrationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $integration_action_id
 * @property int $submission_id
 * @property string $idempotency_key
 * @property IntegrationDeliveryStatus $status
 * @property int $attempts
 * @property array<string, mixed>|null $payload
 * @property int|null $response_status
 * @property string|null $response_body
 * @property string|null $last_error
 * @property Carbon|null $delivered_at
 * @property Carbon|null $next_attempt_at
 */
class IntegrationDelivery extends Model
{
    /** @use HasFactory<IntegrationDeliveryFactory> */
    use HasFactory;

    protected $fillable = [
        'integration_action_id', 'submission_id', 'idempotency_key', 'status', 'attempts',
        'payload', 'response_status', 'response_body', 'last_error', 'delivered_at', 'next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IntegrationDeliveryStatus::class,
            'attempts' => 'integer',
            'payload' => 'array',
            'response_status' => 'integer',
            'delivered_at' => 'datetime',
            'next_attempt_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<IntegrationAction, $this> */
    public function action(): BelongsTo
    {
        return $this->belongsTo(IntegrationAction::class, 'integration_action_id');
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
