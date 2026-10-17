<?php

namespace App\Models;

use Database\Factories\SiteLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Fillable(['id', 'max_active_users'])]
class SiteLimit extends Model
{
    /** @use HasFactory<SiteLimitFactory> */
    use HasFactory;

    public const SINGLETON_ID = 1;

    protected static function booted(): void
    {
        static::creating(function (self $limit): void {
            if ((int) $limit->getKey() !== self::SINGLETON_ID) {
                throw new LogicException('SiteLimit must use singleton ID 1.');
            }
        });
    }

    protected function casts(): array
    {
        return ['max_active_users' => 'integer'];
    }
}
