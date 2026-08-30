<?php

namespace App\Policies;

use App\Models\Announcement;
use App\Models\User;

class AnnouncementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSystemOwner();
    }

    public function create(User $user): bool
    {
        return $user->isSystemOwner();
    }

    public function update(User $user, Announcement $announcement): bool
    {
        return $user->isSystemOwner();
    }

    public function publish(User $user, Announcement $announcement): bool
    {
        return $user->isSystemOwner();
    }

    public function archive(User $user, Announcement $announcement): bool
    {
        return $user->isSystemOwner();
    }
}
