<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Cached, settings-driven branding used by the login page and auth layout.
 * All reads go through Setting::get (cache-backed), so hot pages never
 * query the settings table.
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

    public static function backgroundUrl(): ?string
    {
        $path = Setting::get('brand.login_background');

        return $path !== null && $path !== ''
            ? Storage::disk('public')->url($path)
            : null;
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

    public static function registrationOpen(): bool
    {
        return Setting::get('auth.allow_registration', 'true') !== 'false';
    }
}
