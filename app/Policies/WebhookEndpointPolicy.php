<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEndpoint;

class WebhookEndpointPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('webhooks.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('webhooks.manage');
    }

    public function update(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('webhooks.manage');
    }

    public function delete(User $user, WebhookEndpoint $endpoint): bool
    {
        return $user->can('webhooks.manage');
    }
}
