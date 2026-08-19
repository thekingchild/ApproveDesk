<?php

namespace App\Policies;

use App\Models\RequestType;
use App\Models\User;

class RequestTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('templates.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('templates.manage');
    }

    public function update(User $user, RequestType $requestType): bool
    {
        return $user->can('templates.manage') && ! $requestType->is_system;
    }

    public function use(User $user, RequestType $requestType): bool
    {
        return $user->can('submissions.create') && $requestType->is_active && $requestType->publishedVersion()->exists();
    }
}
