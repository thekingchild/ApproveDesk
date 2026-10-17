<?php

namespace App\Models;

use Database\Factories\RoleProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['role_id', 'label', 'slug', 'description', 'is_builtin', 'is_protected', 'is_archived', 'created_by', 'updated_by'])]
class RoleProfile extends Model
{
    /** @use HasFactory<RoleProfileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_builtin' => 'boolean',
            'is_protected' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
