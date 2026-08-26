<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WorkflowTemplate;

class WorkflowTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('workflows.view');
    }

    public function create(User $user): bool
    {
        return $user->can('workflows.manage');
    }

    public function update(User $user, WorkflowTemplate $template): bool
    {
        return $user->can('workflows.manage');
    }

    public function publish(User $user, WorkflowTemplate $template): bool
    {
        return $user->can('workflows.publish');
    }
}
