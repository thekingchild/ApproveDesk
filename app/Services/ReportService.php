<?php

namespace App\Services;

use App\Enums\AssigneeType;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only reporting queries used by the role-aware dashboard.
 */
final class ReportService
{
    /**
     * @return array{
     *     organization: bool,
     *     can_submit: bool,
     *     can_approve: bool,
     *     counts: array{total: int, in_review: int, approved: int, rejected: int},
     *     action_required: int,
     *     pending_others: int,
     *     completed_approvals: int,
     *     recent: Collection<int, Submission>
     * }
     */
    public function dashboard(User $user): array
    {
        $organization = $user->can('submissions.view.all');
        $submissions = Submission::query()
            ->when(! $organization, fn (Builder $query) => $query->where('submitter_id', $user->id));

        $counts = (clone $submissions)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $actionRequiredIds = $user->can('approvals.act')
            ? $this->actionRequiredQuery($user)->pluck('id')
            : collect();

        $involvedActive = $user->can('approvals.act')
            ? WorkflowInstance::query()
                ->whereIn('status', [WorkflowStatus::Active, WorkflowStatus::OnHold])
                ->whereHas('actions', fn (Builder $query) => $query->where('actor_id', $user->id))
                ->when($actionRequiredIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('id', $actionRequiredIds))
                ->count()
            : 0;

        $completedApprovals = $user->can('approvals.act')
            ? WorkflowInstance::query()
                ->whereIn('status', [WorkflowStatus::Completed, WorkflowStatus::Rejected, WorkflowStatus::Cancelled])
                ->whereHas('actions', fn (Builder $query) => $query->where('actor_id', $user->id))
                ->count()
            : 0;

        return [
            'organization' => $organization,
            'can_submit' => $user->can('submissions.create'),
            'can_approve' => $user->can('approvals.act'),
            'counts' => [
                'total' => (int) $counts->sum(),
                'in_review' => (int) ($counts[SubmissionStatus::InReview->value] ?? 0),
                'approved' => (int) ($counts[SubmissionStatus::Approved->value] ?? 0),
                'rejected' => (int) ($counts[SubmissionStatus::Rejected->value] ?? 0),
            ],
            'action_required' => $actionRequiredIds->count(),
            'pending_others' => $involvedActive,
            'completed_approvals' => $completedApprovals,
            'recent' => (clone $submissions)
                ->with(['submitter', 'workflowInstance.currentStep'])
                ->latest('updated_at')
                ->limit(8)
                ->get(),
        ];
    }

    /** @return Builder<WorkflowInstance> */
    private function actionRequiredQuery(User $user): Builder
    {
        $user->loadMissing('roles');
        $roleIds = $user->roles->pluck('id')->all();

        return WorkflowInstance::query()
            ->where('status', WorkflowStatus::Active)
            ->whereHas('currentStep', function (Builder $query) use ($user, $roleIds): void {
                $query->where(function (Builder $assigned) use ($user, $roleIds): void {
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
    }
}
