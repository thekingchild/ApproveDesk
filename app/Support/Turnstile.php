<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class Turnstile
{
    public const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const TOKEN_FIELD = 'cf-turnstile-response';

    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    public static function enabled(): bool
    {
        return Setting::get('turnstile.enabled', '0') === '1'
            && self::siteKey() !== ''
            && self::secret() !== '';
    }

    public static function siteKey(): string
    {
        return trim((string) Setting::get('turnstile.site_key', ''));
    }

    public static function hasSecret(): bool
    {
        return self::secret() !== '';
    }

    public static function secret(): string
    {
        $stored = (string) Setting::get('turnstile.secret', '');
        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (Throwable) {
            return '';
        }
    }

    public static function putSecret(?string $secret): void
    {
        $trimmed = is_string($secret) ? trim($secret) : '';
        if ($trimmed === '') {
            return;
        }

        Setting::put('turnstile.secret', Crypt::encryptString($trimmed));
    }

    public static function clearSecret(): void
    {
        Setting::put('turnstile.secret', null);
    }

    public static function verify(string $token, ?string $ip = null): bool
    {
        if ($token === '' || self::secret() === '') {
            return false;
        }

        $payload = self::siteverify($token, $ip);
        if ($payload === null) {
            return false;
        }

        $ok = ($payload['success'] ?? false) === true;
        if (! $ok) {
            Log::info('turnstile.rejected', [
                'error_codes' => $payload['error-codes'] ?? [],
            ]);
        }

        return $ok;
    }

    /**
     * Call siteverify with a dummy token so an administrator can confirm
     * Cloudflare answers. Never returns the secret.
     *
     * @return array{reachable: bool, success: bool, error_codes: list<string>, message: string}
     */
    public static function probe(): array
    {
        if (self::secret() === '') {
            return [
                'reachable' => false,
                'success' => false,
                'error_codes' => [],
                'message' => __('Save a secret first.'),
            ];
        }

        $payload = self::siteverify('dummy-token');
        if ($payload === null) {
            return [
                'reachable' => false,
                'success' => false,
                'error_codes' => [],
                'message' => __('Could not reach Cloudflare.'),
            ];
        }

        $codes = [];
        foreach ($payload['error-codes'] ?? [] as $code) {
            if (is_string($code) && $code !== '') {
                $codes[] = $code;
            }
        }

        return [
            'reachable' => true,
            'success' => ($payload['success'] ?? false) === true,
            'error_codes' => $codes,
            'message' => __('Cloudflare answered. A test token is expected to fail.'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function siteverify(string $token, ?string $ip = null): ?array
    {
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->asForm()
                ->post(self::SITEVERIFY_URL, array_filter([
                    'secret' => self::secret(),
                    'response' => $token,
                    'remoteip' => $ip,
                ], fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (Throwable) {
            Log::warning('turnstile.verify_failed', ['reason' => 'timeout_or_network']);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('turnstile.verify_failed', ['reason' => 'http_'.$response->status()]);

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }
}
