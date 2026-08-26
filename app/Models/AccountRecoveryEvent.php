<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

#[Fillable(['actor_id', 'target_id', 'action', 'metadata'])]
class AccountRecoveryEvent extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_PASSWORD_RESET = 'password_reset';

    public const ACTION_SECOND_FACTOR_RESET = 'second_factor_reset';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('account_recovery_events is append-only; updates are forbidden.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('account_recovery_events is append-only; deletes are forbidden.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }
}
