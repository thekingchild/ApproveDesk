<?php

namespace App\Policies;

use App\Models\IntegrationConnector;
use App\Models\User;

class IntegrationConnectorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('integrations.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('integrations.manage');
    }

    public function update(User $user, IntegrationConnector $connector): bool
    {
        return $user->can('integrations.manage');
    }

    public function delete(User $user, IntegrationConnector $connector): bool
    {
        return $user->can('integrations.manage');
    }
}
