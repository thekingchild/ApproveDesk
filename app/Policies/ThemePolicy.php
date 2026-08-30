<?php

namespace App\Policies;

use App\Models\Theme;
use App\Models\User;

class ThemePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSystemOwner();
    }

    public function create(User $user): bool
    {
        return $user->isSystemOwner();
    }

    public function update(User $user, Theme $theme): bool
    {
        return $user->isSystemOwner();
    }

    public function enable(User $user, Theme $theme): bool
    {
        return $user->isSystemOwner();
    }

    public function setDefault(User $user, Theme $theme): bool
    {
        return $user->isSystemOwner();
    }

    public function archive(User $user, Theme $theme): bool
    {
        return $user->isSystemOwner();
    }
}
