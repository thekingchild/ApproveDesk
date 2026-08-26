<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;

class Role extends \Spatie\Permission\Models\Role
{
    /** @return HasOne<RoleProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(RoleProfile::class);
    }
}
