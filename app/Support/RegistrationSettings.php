<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Settings-backed self-registration controls (Phase UO): activation mode and
 * the domain allow-list. All reads are cache-backed through Setting::get.
 *
 * Security note: the domain allow-list is an onboarding convenience, not an
 * authentication boundary — anyone can type any domain. Real assurance comes
 * from passwords, 2FA, and passkeys.
 */
final class RegistrationSettings
{
    public const MODE_IMMEDIATE = 'immediate';

    public const MODE_OTP = 'otp';

    /** Activation mode: immediate access, or OTP email confirmation first. */
    public static function mode(): string
    {
        $mode = Setting::get('auth.registration_mode', self::MODE_OTP);

        return in_array($mode, [self::MODE_IMMEDIATE, self::MODE_OTP], true)
            ? $mode
            : self::MODE_OTP;
    }

    public static function requiresOtp(): bool
    {
        return self::mode() === self::MODE_OTP;
    }

    /**
     * Allowed registration domains; empty list means any domain.
     *
     * @return list<string>
     */
    public static function allowedDomains(): array
    {
        $raw = Setting::get('auth.allowed_registration_domains', '');

        return collect(preg_split('/[\r\n,]+/', (string) $raw) ?: [])
            ->map(fn (string $domain): string => mb_strtolower(trim($domain)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** Case-insensitive domain match against the allow-list (empty list allows all). */
    public static function domainAllowed(string $email): bool
    {
        $allowed = self::allowedDomains();

        if ($allowed === []) {
            return true;
        }

        $domain = mb_strtolower(trim(strrchr(trim($email), '@') ?: '', '@'));

        if ($domain === '' || ! str_contains($email, '@')) {
            return false;
        }

        return in_array($domain, $allowed, true);
    }
}
