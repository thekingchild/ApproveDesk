<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Role extends \Spatie\Permission\Models\Role
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /** @return HasOne<RoleProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(RoleProfile::class);
    }
}
