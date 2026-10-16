<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannelKey;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads and writes optional-channel enablement, encrypted credentials,
 * and last-test status. Tokens never go to logs.
 */
final class ChannelSettings
{
    public function isEnabled(NotificationChannelKey $channel): bool
    {
        return Setting::get($this->enabledKey($channel), 'false') === 'true';
    }

    public function setEnabled(NotificationChannelKey $channel, bool $enabled): void
    {
        Setting::put($this->enabledKey($channel), $enabled ? 'true' : 'false');
    }

    /**
     * @return array<string, string>
     */
    public function credentials(NotificationChannelKey $channel): array
    {
        $stored = $this->decryptMap(Setting::get($this->credentialsKey($channel)));
        $fromEnv = $this->environmentCredentials($channel);

        return array_filter([...$fromEnv, ...$stored], fn (string $value): bool => $value !== '');
    }

    public function hasCredentials(NotificationChannelKey $channel): bool
    {
        $required = match ($channel) {
            NotificationChannelKey::Teams => ['app_id', 'app_password'],
            NotificationChannelKey::Slack => ['bot_token'],
            NotificationChannelKey::WhatsApp => ['waba_id', 'phone_number_id', 'access_token'],
            NotificationChannelKey::Telegram => ['bot_token'],
            default => [],
        };

        $credentials = $this->credentials($channel);

        foreach ($required as $field) {
            if (($credentials[$field] ?? '') === '') {
                return false;
            }
        }

        return $required !== [];
    }

    /**
     * Merge posted fields into the stored map. Empty posted values keep the current secret.
     *
     * @param  array<string, string|null>  $posted
     */
    public function putCredentials(NotificationChannelKey $channel, array $posted): void
    {
        $current = $this->decryptMap(Setting::get($this->credentialsKey($channel)));

        foreach ($posted as $field => $value) {
            $trimmed = is_string($value) ? trim($value) : '';
            if ($trimmed === '') {
                continue;
            }

            $current[$field] = $trimmed;
        }

        Setting::put($this->credentialsKey($channel), Crypt::encryptString((string) json_encode($current, JSON_THROW_ON_ERROR)));
    }

    public function clearCredentials(NotificationChannelKey $channel): void
    {
        Setting::put($this->credentialsKey($channel), null);
    }

    public function lastTestedAt(NotificationChannelKey $channel): ?string
    {
        $value = Setting::get($this->testedKey($channel));

        return $value === null || $value === '' ? null : $value;
    }

    public function lastError(NotificationChannelKey $channel): ?string
    {
        $value = Setting::get($this->errorKey($channel));

        return $value === null || $value === '' ? null : $value;
    }

    public function recordTest(NotificationChannelKey $channel, bool $ok, string $message): void
    {
        if ($ok) {
            Setting::put($this->testedKey($channel), now()->toIso8601String());
            Setting::put($this->errorKey($channel), null);

            return;
        }

        Setting::put($this->errorKey($channel), $this->redact($channel, $message));
    }

    /**
     * Records the channel health error and returns the redacted message so the
     * driver can report the same text on the delivery row (RV-BUG-15).
     */
    public function recordSendFailure(NotificationChannelKey $channel, string $message): string
    {
        $redacted = $this->redact($channel, $message);
        Setting::put($this->errorKey($channel), $redacted);
        Log::warning('Optional notification channel send failed.', [
            'channel' => $channel->value,
            'error' => $redacted,
        ]);

        return $redacted;
    }

    public function redact(NotificationChannelKey $channel, string $message): string
    {
        $redacted = $message;

        foreach ($this->credentials($channel) as $secret) {
            if ($secret !== '') {
                $redacted = str_replace($secret, '[redacted]', $redacted);
            }
        }

        return $redacted;
    }

    /**
     * @return array<string, string>
     */
    private function environmentCredentials(NotificationChannelKey $channel): array
    {
        $config = config('services.'.$channel->value, []);
        if (! is_array($config)) {
            return [];
        }

        $mapped = [];
        foreach ($config as $field => $value) {
            if (is_string($field) && is_string($value) && $value !== '') {
                $mapped[$field] = $value;
            }
        }

        return $mapped;
    }

    /**
     * @return array<string, string>
     */
    private function decryptMap(?string $payload): array
    {
        if ($payload === null || $payload === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $field => $value) {
            if (is_string($field) && is_string($value) && $value !== '') {
                $map[$field] = $value;
            }
        }

        return $map;
    }

    private function enabledKey(NotificationChannelKey $channel): string
    {
        return 'notifications.'.$channel->value.'.enabled';
    }

    private function credentialsKey(NotificationChannelKey $channel): string
    {
        return 'notifications.'.$channel->value.'.credentials';
    }

    private function testedKey(NotificationChannelKey $channel): string
    {
        return 'notifications.'.$channel->value.'.last_tested_at';
    }

    private function errorKey(NotificationChannelKey $channel): string
    {
        return 'notifications.'.$channel->value.'.last_error';
    }
}
