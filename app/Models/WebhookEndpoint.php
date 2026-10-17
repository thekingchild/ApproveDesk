<?php

namespace App\Models;

use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property array<int, string> $events
 * @property bool $is_active
 * @property int $created_by
 * @property-read User $creator
 * @property-read Collection<int, WebhookDelivery> $deliveries
 */
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    /** The signing secret never appear in arrays or JSON (RV-LAR-05). */
    protected $hidden = ['secret'];

    protected $fillable = ['name', 'url', 'secret', 'events', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class, 'endpoint_id');
    }

    /** @return HasOne<WebhookDelivery, $this> */
    public function latestDelivery(): HasOne
    {
        return $this->hasOne(WebhookDelivery::class, 'endpoint_id')->latestOfMany();
    }
}
