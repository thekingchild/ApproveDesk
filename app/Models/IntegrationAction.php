<?php

namespace App\Models;

use App\Enums\IntegrationActionType;
use Database\Factories\IntegrationActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $connector_id
 * @property int|null $request_type_id
 * @property IntegrationActionType $action
 * @property string $name
 * @property array<string, mixed>|null $configuration
 * @property bool $is_enabled
 */
class IntegrationAction extends Model
{
    /** @use HasFactory<IntegrationActionFactory> */
    use HasFactory;

    protected $fillable = [
        'connector_id', 'request_type_id', 'action', 'name', 'configuration', 'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'action' => IntegrationActionType::class,
            'configuration' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<IntegrationConnector, $this> */
    public function connector(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnector::class, 'connector_id');
    }

    /** @return BelongsTo<RequestType, $this> */
    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    /** @return HasMany<IntegrationDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(IntegrationDelivery::class);
    }

    public function appliesTo(Submission $submission): bool
    {
        if (! $this->is_enabled) {
            return false;
        }

        if ($this->request_type_id === null) {
            return true;
        }

        return (int) $submission->requestTypeVersion?->request_type_id === (int) $this->request_type_id;
    }
}
