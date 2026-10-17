<?php

namespace App\Services\Workflow;

use App\Enums\GroupCompletion;
use App\Enums\TimelineStepState;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplateStep;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Collection;

/**
 * Per-step progress of a request's approval chain, derived from its
 * append-only workflow actions (RV-ARC-02). Moved out of the request page.
 */
final class WorkflowTimeline
{
    private const PROGRESS = [
        WorkflowActionType::Approve,
        WorkflowActionType::Escalate,
        WorkflowActionType::AutoApprove,
        WorkflowActionType::ConditionMatched,
        WorkflowActionType::ConditionNotMatched,
    ];

    /**
     * @param  (Closure(WorkflowTemplateStep): Collection<int, User>)|null  $assignees  lets a caller reuse approvers it already resolved
     * @return list<array{name: string, order: int, state: TimelineStepState, actors: string, approvals: int, required: int|null, acted_at: CarbonInterface|null}>
     */
    public function for(Submission $submission, ?Closure $assignees = null): array
    {
        $instance = $submission->workflowInstance;

        if ($instance === null) {
            return [];
        }

        $assignees ??= fn (WorkflowTemplateStep $step): Collection => $step->assignedUsers($submission);
        $actions = $instance->actions->groupBy('step_id');
        $current = in_array($instance->status, [WorkflowStatus::Active, WorkflowStatus::OnHold], true) ? $instance->current_step_id : null;

        // The chain this request is pinned to, not whatever was published since (RV-BUG-28).
        $steps = $instance->templateVersion->steps ?? $instance->template->steps;

        $timeline = [];

        foreach ($steps as $step) {
            $stepActions = $actions->get($step->id, collect());
            $assigned = $assignees($step);
            $first = $stepActions->first();

            $timeline[] = [
                'name' => $step->name,
                'order' => $step->step_order,
                'state' => match (true) {
                    $stepActions->contains('action', WorkflowActionType::Reject) => TimelineStepState::Rejected,
                    $current === $step->id => TimelineStepState::Active,
                    $stepActions->contains(fn (WorkflowAction $action): bool => in_array($action->action, self::PROGRESS, true)) => TimelineStepState::Done,
                    default => TimelineStepState::Pending,
                },
                'actors' => $step->step_type->isAutomatic() ? (string) __('System') : $assigned->pluck('name')->implode(', '),
                'approvals' => ReviewRound::filter($stepActions, $step->id)
                    ->whereIn('action', [WorkflowActionType::Approve, WorkflowActionType::ApproveWithConditions])
                    ->unique('actor_id')
                    ->count(),
                'required' => match ($step->groupCompletion()) {
                    GroupCompletion::FirstResponse => 1,
                    GroupCompletion::All => $assigned->count(),
                    GroupCompletion::Quorum => $step->quorum_count,
                    default => null,
                },
                'acted_at' => $first instanceof WorkflowAction ? $first->created_at : null,
            ];
        }

        return $timeline;
    }
}
