<?php

namespace App\Models;

use App\Enums\ConnectorKey;
use Database\Factories\IntegrationConnectorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property ConnectorKey $type
 * @property string $name
 * @property bool $is_enabled
 * @property array<string, string>|null $credentials
 * @property Carbon|null $last_tested_at
 * @property string|null $last_error
 * @property Carbon|null $last_request_at
 * @property int $min_interval_seconds
 */
class IntegrationConnector extends Model
{
    /** @use HasFactory<IntegrationConnectorFactory> */
    use HasFactory;

    /** Connector credentials never appear in arrays or JSON (RV-LAR-05). */
    protected $hidden = ['credentials'];

    protected $fillable = [
        'type', 'name', 'is_enabled', 'credentials', 'last_tested_at',
        'last_error', 'last_request_at', 'min_interval_seconds',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConnectorKey::class,
            'is_enabled' => 'boolean',
            'credentials' => 'encrypted:array',
            'last_tested_at' => 'datetime',
            'last_request_at' => 'datetime',
            'min_interval_seconds' => 'integer',
        ];
    }

    /** @return HasMany<IntegrationAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(IntegrationAction::class, 'connector_id');
    }

    /** @return array<string, string> */
    public function credentialMap(): array
    {
        $stored = $this->credentials;

        return is_array($stored) ? array_filter($stored, fn ($value): bool => is_string($value) && $value !== '') : [];
    }

    public function redact(string $message): string
    {
        $redacted = $message;

        foreach ($this->credentialMap() as $secret) {
            $redacted = str_replace($secret, '[redacted]', $redacted);
        }

        return $redacted;
    }
}
