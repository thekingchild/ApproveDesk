<?php

namespace App\Models;

use App\Enums\BackupDestinationKind;
use Database\Factories\BackupDestinationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

/**
 * @property BackupDestinationKind $kind
 */
class BackupDestination extends Model
{
    /** @use HasFactory<BackupDestinationFactory> */
    use HasFactory;

    protected $fillable = ['name', 'kind', 'config', 'enabled', 'last_error', 'last_tested_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => BackupDestinationKind::class,
            'enabled' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BackupSnapshot, $this>
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(BackupSnapshot::class, 'destination_id');
    }

    /**
     * @return array<string, string>
     */
    public function credentials(): array
    {
        $stored = (string) $this->config;
        if ($stored === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($stored), true);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? array_map(strval(...), $decoded) : [];
    }

    /**
     * @param  array<string, string|null>  $posted
     */
    public function putCredentials(array $posted): void
    {
        $current = $this->credentials();

        foreach ($posted as $field => $value) {
            $trimmed = is_string($value) ? trim($value) : '';
            if ($trimmed === '') {
                continue;
            }
            $current[$field] = $trimmed;
        }

        $this->forceFill([
            'config' => Crypt::encryptString((string) json_encode($current, JSON_THROW_ON_ERROR)),
        ])->save();
    }

    public function clearCredentials(): void
    {
        $this->forceFill(['config' => null, 'last_error' => null])->save();
    }

    public function isConfigured(): bool
    {
        $credentials = $this->credentials();

        return match ($this->kind) {
            BackupDestinationKind::Local => true,
            BackupDestinationKind::S3 => ($credentials['key'] ?? '') !== ''
                && ($credentials['secret'] ?? '') !== ''
                && ($credentials['bucket'] ?? '') !== '',
            BackupDestinationKind::GoogleDrive => ($credentials['folder_id'] ?? '') !== ''
                && (($credentials['refresh_token'] ?? '') !== '' || ($credentials['service_account'] ?? '') !== ''),
        };
    }

    public function rememberError(?string $message): void
    {
        $redacted = $message === null
            ? null
            : Str::limit((string) preg_replace('/[A-Za-z0-9_\\-]{20,}/', '[redacted]', $message), 200, '');
        $this->forceFill(['last_error' => $redacted])->save();
    }
}
