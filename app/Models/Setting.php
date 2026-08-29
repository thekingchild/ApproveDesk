<?php

namespace App\Models;

use App\Support\Installer;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * App-wide key/value configuration (branding, quotas, mail overrides).
 * Reads are cached forever per key and invalidated on write, so hot paths
 * never touch the table.
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

    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            return Cache::rememberForever(
                "settings.{$key}",
                fn () => static::query()->where('key', $key)->value('value') ?? $default,
            );
        } catch (Throwable $exception) {
            if (app(Installer::class)->isInstalled()) {
                throw $exception;
            }

            return $default;
        }
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget("settings.{$key}");
    }
}
