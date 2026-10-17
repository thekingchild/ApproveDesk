<?php

namespace App\Services\Themes;

use App\Models\Theme;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

final class ThemeService
{
    public const MINIMUM_CONTRAST_RATIO = 4.5;

    /** Cache key for the enabled themes; dropped whenever a theme is saved or deleted. */
    public const CACHE_KEY = 'themes.enabled';

    public function effectiveFor(?User $user): Theme
    {
        try {
            $themes = $this->enabledThemes();
        } catch (QueryException $exception) {
            if (! Schema::hasTable('themes')) {
                return $this->fallback();
            }

            throw $exception;
        }

        // Resolved from one cached list, so a signed-in page runs no theme queries (RV-PERF-20).
        return ($user?->theme_id ? $themes->firstWhere('id', $user->theme_id) : null)
            ?? $themes->firstWhere('is_default', true)
            ?? $themes->sortBy('id')->first()
            ?? $this->fallback();
    }

    /** @return Collection<int, Theme> */
    private function enabledThemes(): Collection
    {
        return Cache::memo()->rememberForever(
            self::CACHE_KEY,
            fn (): Collection => Theme::query()->where('is_enabled', true)->whereNull('archived_at')->orderBy('id')->get(),
        );
    }

    public static function flushCache(): void
    {
        Cache::memo()->forget(self::CACHE_KEY);
    }

    public function cssVariables(Theme $theme): string
    {
        foreach (['primary_light', 'primary_dark', 'foreground_light', 'foreground_dark'] as $token) {
            if (! preg_match('/^#[0-9A-F]{6}$/', (string) $theme->{$token})) {
                throw new \InvalidArgumentException('Theme contains an invalid color token.');
            }
        }

        return sprintf(':root { --ad-primary: %s; --ad-on-primary: %s; --ad-brand: %s; --ad-brand-hover: color-mix(in srgb, %s, black 15%%); --ad-focus: %s; --ad-surface: #FAFAFA; --ad-on-surface: #18181B; } .dark { --ad-primary: %s; --ad-on-primary: %s; --ad-brand: %s; --ad-brand-hover: color-mix(in srgb, %s, white 15%%); --ad-focus: %s; --ad-surface: #09090B; --ad-on-surface: #FAFAFA; }', $theme->primary_light, $theme->foreground_light, $theme->primary_light, $theme->primary_light, $theme->primary_light, $theme->primary_dark, $theme->foreground_dark, $theme->primary_dark, $theme->primary_dark, $theme->primary_dark);
    }

    public function contrastRatio(string $first, string $second): float
    {
        $firstLuminance = $this->relativeLuminance($first);
        $secondLuminance = $this->relativeLuminance($second);

        return (max($firstLuminance, $secondLuminance) + 0.05)
            / (min($firstLuminance, $secondLuminance) + 0.05);
    }

    private function relativeLuminance(string $color): float
    {
        if (! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            throw new \InvalidArgumentException('Color must be a six-digit hexadecimal value.');
        }

        $channels = array_map(
            static fn (int $offset): float => hexdec(substr($color, $offset, 2)) / 255,
            [1, 3, 5],
        );
        $channels = array_map(
            static fn (float $channel): float => $channel <= 0.04045
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            $channels,
        );

        return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    }

    private function fallback(): Theme
    {
        return new Theme([
            'key' => 'approvedesk',
            'name' => 'ApproveDesk',
            'is_builtin' => true,
            'is_enabled' => true,
            'is_default' => true,
            'primary_light' => '#2563EB',
            'primary_dark' => '#60A5FA',
            'foreground_light' => '#FFFFFF',
            'foreground_dark' => '#172554',
        ]);
    }
}
