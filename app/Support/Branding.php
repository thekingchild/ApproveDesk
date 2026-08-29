<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Cached, settings-driven branding used by the login page, auth layout,
 * signed-in chrome, and document head. All reads go through Setting::get
 * (cache-backed), so hot pages never query the settings table.
 */
final class Branding
{
    public static function tagline(): string
    {
        return (string) Setting::get('brand.tagline', 'Approvals, clearly tracked.');
    }

    public static function accentColor(): string
    {
        return (string) Setting::get('brand.accent_color', '#4f46e5');
    }

    /**
     * Visible application name: the saved meta title, or APP_NAME.
     */
    public static function name(): string
    {
        $title = Setting::get('brand.meta_title');

        return $title !== null && $title !== ''
            ? $title
            : (string) config('app.name', 'ApproveDesk');
    }

    public static function metaTitle(): string
    {
        return self::name();
    }

    public static function metaDescription(): ?string
    {
        $description = Setting::get('brand.meta_description');

        return $description === null || $description === '' ? null : $description;
    }

    public static function backgroundUrl(): ?string
    {
        return self::urlFor(Setting::get('brand.login_background'));
    }

    public static function logoUrl(): ?string
    {
        return self::urlFor(Setting::get('brand.logo'));
    }

    public static function faviconUrl(): ?string
    {
        return self::urlFor(Setting::get('brand.favicon'));
    }

    public static function overlayPercent(): int
    {
        return max(0, min(90, (int) Setting::get('brand.login_background_overlay', '40')));
    }

    public static function customCss(): ?string
    {
        $css = Setting::get('brand.custom_css');

        // Prevent </style> breakout; this is admin-supplied CSS, not HTML.
        return $css === null || $css === '' ? null : str_ireplace('</style', '', $css);
    }

    public static function customCodeHtml(): ?string
    {
        $code = Setting::get('brand.custom_code');

        if ($code === null || trim($code) === '') {
            return null;
        }

        $html = self::normalizeCustomCode(str_replace("\0", '', $code));

        return $html === '' ? null : $html;
    }

    /**
     * @return list<string>
     */
    public static function customCodeOrigins(): array
    {
        $html = self::customCodeHtml();

        if ($html === null) {
            return [];
        }

        preg_match_all('#https://[a-z0-9.-]+(?::\d+)?#i', $html, $matches);

        $origins = [];

        foreach ($matches[0] as $origin) {
            $host = parse_url($origin, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                continue;
            }

            $origins['https://'.strtolower($host)] = true;
        }

        return array_keys($origins);
    }

    private static function normalizeCustomCode(string $code): string
    {
        $trimmed = trim($code);
        $looksLikeMarkup = preg_match('/<\s*(script|noscript|img|iframe|meta|link)\b/i', $trimmed) === 1;

        if (! $looksLikeMarkup) {
            return '<script>'.str_ireplace('</script', '', $trimmed).'</script>';
        }

        $html = preg_replace(
            '/\s+(src|href)\s*=\s*(["\']?)(?:javascript|data|vbscript):[^"\'>\s]*/i',
            '',
            $trimmed,
        );

        return is_string($html) ? $html : $trimmed;
    }

    public static function registrationOpen(): bool
    {
        return Setting::get('auth.allow_registration', 'true') !== 'false';
    }

    public static function landingPageEnabled(): bool
    {
        return Setting::get('brand.landing_page_enabled', 'false') === 'true';
    }

    /**
     * Public URL for a file stored under branding/ on the public disk.
     * Uses the application route so the image is reachable without a
     * storage symlink (common miss on local `artisan serve` and cPanel).
     */
    public static function urlFor(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $file = basename($path);

        if ($file === '' || $file === '.' || $file === '..') {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists('branding/'.$file) && ! $disk->exists($path)) {
            return null;
        }

        return route('branding.asset', ['file' => $file], absolute: false);
    }
}
