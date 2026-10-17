<?php

namespace App\Services\Workflow;

use App\Enums\AssigneeType;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class AssignedStepConstraint
{
    /**
     * @param  Builder<WorkflowInstance>  $query
     * @return Builder<WorkflowInstance>
     */
    public function constrainInstances(Builder $query, User $user): Builder
    {
        $roleIds = $user->getRoleNames()->isEmpty()
            ? []
            : $user->roles()->pluck('id')->all();

        if (SeparationOfDuties::enabled()) {
            $query->whereHas('submission', fn (Builder $submission) => $submission->where('submitter_id', '!=', $user->id));
        }

        return $query->where(function (Builder $eligible) use ($user, $roleIds): void {
            $eligible->whereHas('currentStep', function (Builder $step) use ($user, $roleIds): void {
                $step->where(function (Builder $assigned) use ($user, $roleIds): void {
                    $assigned->where(function (Builder $users) use ($user): void {
                        $users->where('assignee_type', AssigneeType::User)
                            ->whereJsonContains('assignee_ids', $user->id);
                    });

                    if ($roleIds !== []) {
                        $assigned->orWhere(function (Builder $roles) use ($roleIds): void {
                            $roles->where('assignee_type', AssigneeType::Role)
                                ->where(function (Builder $ids) use ($roleIds): void {
                                    foreach ($roleIds as $roleId) {
                                        $ids->orWhereJsonContains('assignee_ids', $roleId);
                                    }
                                });
                        });
                    }

                    if ($user->department_id !== null) {
                        $assigned->orWhere(function (Builder $departments) use ($user): void {
                            $departments->where('assignee_type', AssigneeType::Department)
                                ->whereJsonContains('assignee_ids', $user->department_id);
                        });
                    }
                });
            });

            $eligible->orWhere(function (Builder $managers) use ($user): void {
                $managers->whereHas('currentStep', fn (Builder $step) => $step->where('assignee_type', AssigneeType::RequesterManager))
                    ->whereHas('submission.submitter', fn (Builder $submitter) => $submitter->where('manager_id', $user->id));
            });

            $eligible->orWhere(function (Builder $departmentManagers) use ($user): void {
                $departmentManagers->whereHas('currentStep', fn (Builder $step) => $step->where('assignee_type', AssigneeType::DepartmentManager))
                    ->where(function (Builder $source) use ($user): void {
                        $source->whereHas('submission.department', fn (Builder $department) => $department->where('manager_id', $user->id))
                            ->orWhereHas('submission.submitter.department', fn (Builder $department) => $department->where('manager_id', $user->id));
                    });
            });

            $eligible->orWhere(function (Builder $form) use ($user): void {
                $form->whereHas('currentStep', fn (Builder $step) => $step->where('assignee_type', AssigneeType::FormField))
                    ->whereHas('submission.fieldValues', function (Builder $values) use ($user): void {
                        $values->where('user_value_id', $user->id)
                            ->whereHas('field', function (Builder $field): void {
                                // Select the JSON path through the grammar so it is unquoted
                                // per driver; a raw json_extract kept quotes on MariaDB (RV-BUG-02).
                                $field->where('request_field_definitions.key', '=', function (QueryBuilder $step): void {
                                    $step->select('workflow_template_steps.assignee_config->field_key')
                                        ->from('workflow_template_steps')
                                        ->whereColumn('workflow_template_steps.id', 'workflow_instances.current_step_id');
                                });
                            });
                    });
            });
        });
    }
}
