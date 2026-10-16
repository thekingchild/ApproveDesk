<?php

namespace App\Services;

use App\Enums\AssigneeType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Workflow\AssignedStepConstraint;
use Illuminate\Database\Eloquent\Builder;

/**
 * SubmissionPolicy::view() as a query constraint, so lists and search can
 * filter in SQL instead of authorizing every row in PHP (RV-PERF-07).
 *
 * It may return a few extra rows (steps resolved by an external system can
 * only be checked per request) but never hides one the policy allows. Callers
 * that show results still authorize each displayed row.
 */
final class SubmissionVisibility
{
    public function __construct(private readonly AssignedStepConstraint $assignedSteps) {}

    public function seesEverything(User $user): bool
    {
        return $user->can('submissions.view.all')
            || ($user->can('audit.view') && $user->hasActiveAuditorAccess());
    }

    /**
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function constrain(Builder $query, User $user): Builder
    {
        if ($this->seesEverything($user)) {
            return $query;
        }

        return $query->where(function (Builder $visible) use ($user): void {
            $visible->where('submitter_id', $user->id)
                // Followers someone else added (following yourself grants nothing; RV-SEC-08).
                ->orWhereHas('watchers', fn (Builder $watcher) => $watcher
                    ->where('user_id', $user->id)
                    ->where(fn (Builder $addedBy) => $addedBy->whereNull('added_by_id')->orWhere('added_by_id', '!=', $user->id)))
                ->orWhereIn('id', $this->assignedSteps->constrainInstances(WorkflowInstance::query(), $user)->select('submission_id'))
                ->orWhereIn('id', WorkflowInstance::query()
                    ->select('submission_id')
                    ->whereHas('currentStep', fn (Builder $step) => $step->where('assignee_type', AssigneeType::External)))
                ->orWhereHas('workflowInstance.delegations', fn (Builder $delegation) => $delegation
                    ->where('starts_at', '<=', now())
                    ->where('expires_at', '>', now())
                    ->where('delegate_id', $user->id)
                    ->whereColumn('delegations.step_id', 'workflow_instances.current_step_id'))
                ->orWhereHas('workflowInstance.actions', fn (Builder $action) => $action->where('actor_id', $user->id));
        });
    }
}
