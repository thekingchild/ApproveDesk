<?php

namespace App\Policies;

use App\Models\RequestType;
use App\Models\User;

class RequestTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('request_types.view');
    }

    public function create(User $user): bool
    {
        return $user->can('request_types.manage');
    }

    public function update(User $user, RequestType $requestType): bool
    {
        return $user->can('request_types.manage') && ! $requestType->is_system;
    }

    public function assignWorkflow(User $user, RequestType $requestType): bool
    {
        return $user->can('workflows.assign');
    }

    public function publish(User $user): bool
    {
        return $user->can('request_types.publish');
    }

    public function use(User $user, RequestType $requestType): bool
    {
        return $user->can('submissions.create') && $requestType->is_active && $requestType->publishedVersion()->exists();
    }
}
