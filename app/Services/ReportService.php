<?php

namespace App\Services;

use App\Enums\SubmissionStatus;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Workflow\ActionableStepConstraint;
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
     *     admin_metrics: array<string, mixed>|null,
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
        $homeMode = $this->homeMode($user, $canSubmit, $canApprove, $organization);
        $administrator = $homeMode === 'administrator';

        return [
            'home_mode' => $homeMode,
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
            'admin_metrics' => $administrator ? app(AnalyticsService::class)->dashboard(30) : null,
            'recent' => (clone $submissions)
                ->with(['submitter', 'workflowInstance.currentStep'])
                ->latest('updated_at')
                ->limit(8)
                ->get(),
        ];
    }

    private function homeMode(User $user, bool $canSubmit, bool $canApprove, bool $organization): string
    {
        if ($user->can('dashboard.admin')) {
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

    /**
     * The engine's "can act" rule in SQL, shared with every list (RV-ARC-01).
     *
     * @return Builder<WorkflowInstance>
     */
    private function actionRequiredQuery(User $user): Builder
    {
        return app(ActionableStepConstraint::class)->constrain(WorkflowInstance::query(), $user);
    }
}
