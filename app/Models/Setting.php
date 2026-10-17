<?php

namespace App\Models;

use App\Services\Install\Installer;
use App\Support\SystemSettings;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * App-wide key/value configuration (branding, quotas, mail overrides).
 * All settings are cached together as one key => value map (memoised per
 * request on top of the cache store) and the map is dropped on every write,
 * so hot paths never touch the table (ADR 0010).
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        // Any Eloquent write (put, seeders' firstOrCreate, deletes) invalidates the cache.
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }

    /** One cache entry holds every setting; it is read once per request (RV-PERF, lead L6). */
    public const CACHE_KEY = 'settings.all';

    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            return self::cached()[$key] ?? $default;
        } catch (Throwable $exception) {
            if (app(Installer::class)->isInstalled()) {
                throw $exception;
            }

            return $default;
        }
    }

    /**
     * Every stored setting as key => value. Memoised for the request on top of the
     * configured store, so a page reads the cache once instead of once per key.
     * Defaults are never cached (a missing key returns each caller's own default).
     *
     * @return array<string, string|null>
     */
    public static function cached(): array
    {
        /** @var array<string, string|null> */
        return Cache::memo()->rememberForever(
            self::CACHE_KEY,
            fn (): array => static::query()->pluck('value', 'key')->all(),
        );
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        self::flushCache();

        if (str_starts_with($key, 'system.')) {
            SystemSettings::flush();
        }
    }

    public static function forget(string $key): void
    {
        static::query()->where('key', $key)->delete();

        self::flushCache();
    }

    /** Call after anything replaces the settings table wholesale (e.g. a database promote). */
    public static function flushCache(): void
    {
        Cache::memo()->forget(self::CACHE_KEY);
    }
}
