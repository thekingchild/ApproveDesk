<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SystemSettings
{
    public const MASK = '••••••••';

    public static function value(string $key, mixed $fallback = null): mixed
    {
        $sentinel = '__approvedesk_no_system_override__';
        $value = Setting::get('system.'.$key, $sentinel);

        return $value === $sentinel ? $fallback : $value;
    }

    public static function hasOverride(string $key): bool
    {
        return self::value($key, null) !== null;
    }

    public static function secret(string $name, ?string $fallback = null): ?string
    {
        $encrypted = self::value('secret.'.$name);
        if (! is_string($encrypted) || $encrypted === '') {
            return $fallback;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            Log::warning('A stored system credential could not be decrypted.', ['credential' => $name]);

            return $fallback;
        }
    }

    public static function hasSecret(string $name): bool
    {
        return self::secret($name) !== null;
    }

    public static function maskedSecret(string $name): ?string
    {
        return self::hasSecret($name) ? self::MASK : null;
    }

    public static function put(string $key, string|int|bool|null $value): void
    {
        Setting::put('system.'.$key, is_bool($value) ? ($value ? '1' : '0') : ($value === null ? null : (string) $value));
    }

    public static function forget(string $key): void
    {
        Setting::query()->where('key', 'system.'.$key)->delete();
        Cache::forget('settings.system.'.$key);
    }

    public static function putSecret(string $name, string $value): void
    {
        self::put('secret.'.$name, Crypt::encryptString($value));
    }

    public static function forgetSecret(string $name): void
    {
        self::forget('secret.'.$name);
    }

    public static function mailTransport(): string
    {
        $transport = (string) self::value('mail.transport', config('mail.default', 'log'));

        if ($transport === 'log' && ! config('mail.allow_log_transport')) {
            return (string) config('mail.default', 'smtp');
        }

        return in_array($transport, ['smtp', 'ses', 'postmark', 'resend', 'sendmail', 'log'], true) ? $transport : 'smtp';
    }

    public static function mailFromAddress(): string
    {
        return (string) self::value('mail.from_address', config('mail.from.address'));
    }

    public static function mailFromName(): string
    {
        return (string) self::value('mail.from_name', config('mail.from.name'));
    }

    public static function sessionLifetime(): int
    {
        return max(5, min(1440, (int) self::value('session.lifetime', config('session.lifetime', 120))));
    }

    public static function expireOnClose(): bool
    {
        return self::value('session.expire_on_close', config('session.expire_on_close') ? '1' : '0') === '1';
    }

    public static function oauthEnabled(string $provider): bool
    {
        $configured = filled(config("services.oauth.{$provider}.client_id")) && filled(config("services.oauth.{$provider}.client_secret"));

        return self::value("oauth.{$provider}.enabled", $configured ? '1' : '0') === '1' && $configured;
    }

    public static function applyRuntimeConfig(): void
    {
        $transport = self::mailTransport();
        config([
            'mail.default' => $transport,
            'mail.from.address' => self::mailFromAddress(),
            'mail.from.name' => self::mailFromName(),
            'session.lifetime' => self::sessionLifetime(),
            'session.expire_on_close' => self::expireOnClose(),
        ]);

        foreach (['host', 'port', 'scheme', 'username'] as $field) {
            config(["mail.mailers.smtp.{$field}" => self::value("mail.smtp.{$field}", config("mail.mailers.smtp.{$field}"))]);
        }
        config(['mail.mailers.smtp.password' => self::secret('smtp_password', config('mail.mailers.smtp.password'))]);

        config([
            'services.ses.key' => self::secret('ses_key', config('services.ses.key')),
            'services.ses.secret' => self::secret('ses_secret', config('services.ses.secret')),
            'services.ses.region' => self::value('mail.ses.region', config('services.ses.region')),
            'services.postmark.key' => self::secret('postmark_token', config('services.postmark.key')),
            'services.resend.key' => self::secret('resend_key', config('services.resend.key')),
        ]);

        foreach (['google', 'microsoft', 'github'] as $provider) {
            config([
                "services.oauth.{$provider}.client_id" => self::value("oauth.{$provider}.client_id", config("services.oauth.{$provider}.client_id")),
                "services.oauth.{$provider}.client_secret" => self::secret("oauth_{$provider}_secret", config("services.oauth.{$provider}.client_secret")),
            ]);
        }
        config(['services.oauth.microsoft.tenant' => self::value('oauth.microsoft.tenant', config('services.oauth.microsoft.tenant', 'common'))]);
    }
}
