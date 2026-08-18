<?php

namespace App\Services;

use App\Enums\AssigneeType;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
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
     *     home_mode: string,
     *     organization: bool,
     *     can_submit: bool,
     *     can_approve: bool,
     *     counts: array{total: int, in_review: int, approved: int, rejected: int},
     *     draft_count: int,
     *     needs_response_count: int,
     *     requester_attention: int,
     *     action_required: int,
     *     action_required_items: Collection<int, WorkflowInstance>,
     *     pending_others: int,
     *     completed_approvals: int,
     *     recent: Collection<int, Submission>
     * }
     */
    public function dashboard(User $user): array
    {
        $organization = $user->can('submissions.view.all');
        $canSubmit = $user->can('submissions.create');
        $canApprove = $user->can('approvals.act');
        $submissions = Submission::query()
            ->when(! $organization, fn (Builder $query) => $query->where('submitter_id', $user->id));
        $ownSubmissions = Submission::query()->where('submitter_id', $user->id);

        $counts = (clone $submissions)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $actionRequiredIds = $canApprove
            ? $this->actionRequiredQuery($user)->pluck('id')
            : collect();

        $actionRequiredItems = WorkflowInstance::query()
            ->whereIn('id', $actionRequiredIds)
            ->with(['submission.submitter', 'currentStep'])
            ->get()
            ->sortBy(fn (WorkflowInstance $instance) => $instance->deadlineAt()?->getTimestamp() ?? PHP_INT_MAX)
            ->take(5)
            ->values();

        $involvedActive = $canApprove
            ? WorkflowInstance::query()
                ->whereIn('status', [WorkflowStatus::Active, WorkflowStatus::OnHold])
                ->whereHas('actions', fn (Builder $query) => $query->where('actor_id', $user->id))
                ->when($actionRequiredIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('id', $actionRequiredIds))
                ->count()
            : 0;

        $completedApprovals = $canApprove
            ? WorkflowInstance::query()
                ->whereIn('status', [WorkflowStatus::Completed, WorkflowStatus::Rejected, WorkflowStatus::Cancelled])
                ->whereHas('actions', fn (Builder $query) => $query->where('actor_id', $user->id))
                ->count()
            : 0;

        $draftCount = (clone $ownSubmissions)->where('status', SubmissionStatus::Draft)->count();
        $needsResponseCount = (clone $ownSubmissions)
            ->whereIn('status', [SubmissionStatus::Returned, SubmissionStatus::AwaitingInformation])
            ->count();

        return [
            'home_mode' => $this->homeMode($user, $canSubmit, $canApprove, $organization),
            'organization' => $organization,
            'can_submit' => $canSubmit,
            'can_approve' => $canApprove,
            'counts' => [
                'total' => (int) $counts->sum(),
                'in_review' => (int) ($counts[SubmissionStatus::InReview->value] ?? 0),
                'approved' => (int) ($counts[SubmissionStatus::Approved->value] ?? 0),
                'rejected' => (int) ($counts[SubmissionStatus::Rejected->value] ?? 0),
            ],
            'draft_count' => $draftCount,
            'needs_response_count' => $needsResponseCount,
            'requester_attention' => $draftCount + $needsResponseCount,
            'action_required' => $actionRequiredIds->count(),
            'action_required_items' => $actionRequiredItems,
            'pending_others' => $involvedActive,
            'completed_approvals' => $completedApprovals,
            'recent' => (clone $submissions)
                ->with(['submitter', 'workflowInstance.currentStep'])
                ->latest('updated_at')
                ->limit(8)
                ->get(),
        ];
    }

    private function homeMode(User $user, bool $canSubmit, bool $canApprove, bool $organization): string
    {
        if ($user->hasAnyRole(['super-admin', 'admin'])) {
            return 'administrator';
        }

        if ($canSubmit && $canApprove) {
            return 'mixed';
        }

        if ($canApprove) {
            return 'approver';
        }

        if ($canSubmit) {
            return 'requester';
        }

        return $organization ? 'administrator' : 'viewer';
    }

    /** @return Builder<WorkflowInstance> */
    private function actionRequiredQuery(User $user): Builder
    {
        $user->loadMissing('roles');
        $roleIds = $user->roles->pluck('id')->all();

        return WorkflowInstance::query()
            ->where('status', WorkflowStatus::Active)
            ->whereDoesntHave('actions', function (Builder $query) use ($user): void {
                $query->where('actor_id', $user->id)
                    ->whereColumn('workflow_actions.step_id', 'workflow_instances.current_step_id')
                    ->whereIn('action', [
                        WorkflowActionType::Approve,
                        WorkflowActionType::Reject,
                        WorkflowActionType::Escalate,
                    ]);
            })
            ->where(function (Builder $eligible) use ($user, $roleIds): void {
                $eligible->whereHas('currentStep', function (Builder $query) use ($user, $roleIds): void {
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
                })->orWhereHas('delegations', function (Builder $query) use ($user): void {
                    $query->where('starts_at', '<=', now())
                        ->where('expires_at', '>', now())
                        ->where('delegate_id', $user->id)
                        ->whereColumn('delegations.step_id', 'workflow_instances.current_step_id');
                });
            })
            ->whereDoesntHave('delegations', function (Builder $query) use ($user): void {
                $query->where('starts_at', '<=', now())
                    ->where('expires_at', '>', now())
                    ->where('delegator_id', $user->id)
                    ->whereColumn('delegations.step_id', 'workflow_instances.current_step_id');
            });
    }
}
