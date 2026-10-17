<?php

namespace App\Services\Workflow;

use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\SystemRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/** Every named user, role and department exists and may be assigned (never the System owner or the super-admin role). */
final class WorkflowAssigneeGuard
{
    /** @param array<int, WorkflowTemplateStepData> $steps */
    public function assertAssignable(array $steps): void
    {
        foreach ($steps as $index => $step) {
            $ids = array_values(array_unique(array_map('intval', $step->assigneeIds)));
            if ($ids === []) {
                continue;
            }

            $valid = match ($step->assigneeType) {
                AssigneeType::User => User::query()
                    ->assignable()
                    ->where('is_active', true)
                    ->whereKey($ids)
                    ->count() === count($ids),
                AssigneeType::Role => Role::query()
                    ->whereKey($ids)
                    ->where('name', '!=', SystemRole::SuperAdmin->value)
                    ->count() === count($ids),
                AssigneeType::Department => Department::query()->whereKey($ids)->count() === count($ids)
                    && ! User::query()
                        ->whereIn('department_id', $ids)
                        ->whereNotIn('id', User::query()->assignable()->select('id'))
                        ->exists(),
                default => true,
            };

            if (! $valid) {
                throw ValidationException::withMessages([
                    "steps.{$index}.assigneeIds" => __('One or more selected assignees are unavailable.'),
                ]);
            }
        }
    }
}
