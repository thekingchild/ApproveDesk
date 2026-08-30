<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ThemeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property bool $is_builtin
 * @property bool $is_enabled
 * @property bool $is_default
 * @property string $primary_light
 * @property string $primary_dark
 * @property string $foreground_light
 * @property string $foreground_dark
 * @property CarbonImmutable|null $archived_at
 */
class Theme extends Model
{
    /** @use HasFactory<ThemeFactory> */
    use HasFactory;

    protected $fillable = ['key', 'name', 'is_builtin', 'is_enabled', 'is_default', 'primary_light', 'primary_dark', 'foreground_light', 'foreground_dark', 'archived_at'];

    protected function casts(): array
    {
        return ['is_builtin' => 'boolean', 'is_enabled' => 'boolean', 'is_default' => 'boolean', 'archived_at' => 'immutable_datetime'];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
