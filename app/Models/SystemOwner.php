<?php

namespace App\Models;

use App\Services\Users\SystemOwnerService;
use Database\Factories\SystemOwnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['id', 'user_id'])]
class SystemOwner extends Model
{
    /** @use HasFactory<SystemOwnerFactory> */
    use HasFactory;

    public const SINGLETON_ID = 1;

    protected static function booted(): void
    {
        static::creating(function (self $owner): void {
            if ((int) $owner->getKey() !== self::SINGLETON_ID) {
                throw new LogicException('SystemOwner must use singleton ID 1.');
            }
        });

        static::saved(function (): void {
            app(SystemOwnerService::class)->forget();
        });

        static::deleted(function (): void {
            app(SystemOwnerService::class)->forget();
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
