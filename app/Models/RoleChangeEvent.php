<?php

namespace App\Models;

use App\Enums\RoleChangeAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable(['actor_id', 'role_id', 'target_user_id', 'action', 'before', 'after'])]
class RoleChangeEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'action' => RoleChangeAction::class];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('role_change_events is append-only; updates are forbidden.');
        });
        static::deleting(function (): never {
            throw new LogicException('role_change_events is append-only; deletes are forbidden.');
        });
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }
}
