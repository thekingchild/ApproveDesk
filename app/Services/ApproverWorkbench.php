<?php

namespace App\Services;

use App\Enums\AssigneeType;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Workflow\AssignedStepConstraint;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\ReviewRound;
use App\Support\SeparationOfDuties;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ApproverWorkbench
{
    /** Decided requests shown under Completed; older ones are in reports. */
    public const COMPLETED_LIMIT = 100;

    public function __construct(private readonly AssignedStepConstraint $assignedSteps) {}

    /**
     * @return array<int, array{
     *     instance: WorkflowInstance,
     *     needs_me: bool,
     *     delegation: 'direct'|'delegated_to_me'|'delegated_by_me',
     *     deadline: CarbonInterface|null,
     *     last_activity: Carbon|null
     * }>
     */
    public function itemsFor(User $user): array
    {
        return AssigneeResolver::batch(function () use ($user): array {
            // Narrow in SQL before the per-item eligibility check (RV-PERF-08):
            // this used to load every active workflow in the organization.
            $recentlyDecided = WorkflowInstance::query()
                ->whereNotIn('status', [WorkflowStatus::Active->value, WorkflowStatus::OnHold->value])
                ->whereHas('actions', fn ($actions) => $actions->where('actor_id', $user->id))
                ->latest('updated_at')
                ->limit(self::COMPLETED_LIMIT)
                ->pluck('id');

            $instances = WorkflowInstance::query()
                ->with([
                    'submission' => fn ($submission) => $submission->withCount('files'),
                    'submission.submitter.department',
                    'submission.department',
                    'currentStep',
                    'actions',
                    'delegations.delegate:id,is_active',
                ])
                ->where(function ($query) use ($user, $recentlyDecided): void {
                    $query->where(function ($open) use ($user): void {
                        $open->where('status', WorkflowStatus::Active->value)
                            ->where(fn ($mine) => $this->couldHoldCurrentStep($mine, $user));
                    })
                        ->orWhere(function ($waiting) use ($user): void {
                            $waiting->whereIn('status', [WorkflowStatus::Active->value, WorkflowStatus::OnHold->value])
                                ->whereHas('actions', fn ($actions) => $actions->where('actor_id', $user->id));
                        })
                        ->orWhereIn('id', $recentlyDecided);
                })
                ->get();

            return $instances
                ->map(function (WorkflowInstance $instance) use ($user): ?array {
                    $eligibility = $this->eligibility($instance, $user);
                    $involved = $instance->actions->contains('actor_id', $user->id);

                    if (! $involved && ! $eligibility['eligible'] && ! $eligibility['delegated_by_me']) {
                        return null;
                    }

                    return [
                        'instance' => $instance,
                        'needs_me' => $instance->status === WorkflowStatus::Active
                            && $eligibility['eligible']
                            && ! $this->hasDecidedCurrentStep($instance, $user),
                        'delegation' => match (true) {
                            $eligibility['delegated_to_me'] => 'delegated_to_me',
                            $eligibility['delegated_by_me'] => 'delegated_by_me',
                            default => 'direct',
                        },
                        'deadline' => $instance->deadlineAt(),
                        'last_activity' => $instance->actions->isEmpty()
                            ? $instance->submission->updated_at
                            : $instance->actions->last()->created_at,
                    ];
                })
                ->filter()
                ->sort($this->compareItems(...))
                ->values()
                ->all();
        });
    }

    /**
     * The request after (or before) $submission in the user's "needs my
     * decision" queue, worked out only when asked (RV-PERF-09).
     */
    public function neighbour(User $user, int $submissionId, string $direction): ?int
    {
        $queue = collect($this->itemsFor($user))
            ->filter(fn (array $item): bool => $item['needs_me'])
            ->map(fn (array $item): int => $item['instance']->submission_id)
            ->values();

        $index = $queue->search($submissionId);
        if ($index === false) {
            return $queue->first();
        }

        return $queue->get($direction === 'previous' ? $index - 1 : $index + 1);
    }

    /**
     * Superset of the active workflows whose current step this user may act
     * on: assigned (SQL-resolvable types), resolved by an external system
     * (checked per item), or delegated to or by the user.
     *
     * @param  Builder<WorkflowInstance>  $query
     */
    private function couldHoldCurrentStep(Builder $query, User $user): void
    {
        $this->assignedSteps->constrainInstances($query, $user)
            ->orWhereHas('currentStep', fn ($step) => $step->where('assignee_type', AssigneeType::External))
            ->orWhereHas('delegations', fn ($delegation) => $delegation
                ->where('starts_at', '<=', now())
                ->where('expires_at', '>', now())
                ->where(fn ($party) => $party->where('delegate_id', $user->id)->orWhere('delegator_id', $user->id))
                ->whereColumn('delegations.step_id', 'workflow_instances.current_step_id'));
    }

    /** The engine's own check, so batch approval and retries agree with the request page (RV-ARC-01). */
    public function canAct(WorkflowInstance $instance, User $user): bool
    {
        return app(WorkflowEngine::class)->canAct($instance, $user);
    }

    /**
     * In-memory mirror of WorkflowEngine::canAct() for the list, which checks
     * many instances at once; keep the two in step.
     *
     * @return array{eligible: bool, delegated_to_me: bool, delegated_by_me: bool}
     */
    private function eligibility(WorkflowInstance $instance, User $user): array
    {
        $activeDelegations = $instance->delegations->filter(fn ($delegation) => $delegation->step_id === $instance->current_step_id
            && $delegation->starts_at <= now()
            && $delegation->expires_at > now());
        $delegatedToUser = $activeDelegations->contains('delegate_id', $user->id);
        $delegatedByUser = $activeDelegations->contains('delegator_id', $user->id);
        // A delegation to someone since deactivated no longer holds the step (RV-ENG-12).
        $heldByMyDelegate = $activeDelegations->contains(fn ($delegation) => $delegation->delegator_id === $user->id
            && $delegation->delegate->is_active);
        $direct = $instance->currentStep?->assignedUsers($instance->submission)->contains($user) ?? false;

        return [
            'eligible' => ($delegatedToUser || ($direct && ! $heldByMyDelegate))
                && ! SeparationOfDuties::forbids($user, $instance->submission),
            'delegated_to_me' => $delegatedToUser,
            'delegated_by_me' => $delegatedByUser,
        ];
    }

    private function hasDecidedCurrentStep(WorkflowInstance $instance, User $user): bool
    {
        return ReviewRound::forSeat($instance->actions, $instance->current_step_id, (int) $user->id)
            ->whereIn('action', [
                WorkflowActionType::Approve,
                WorkflowActionType::ApproveWithConditions,
                WorkflowActionType::Reject,
                WorkflowActionType::Escalate,
            ])
            ->isNotEmpty();
    }

    /**
     * @param  array{instance: WorkflowInstance, needs_me: bool, deadline: CarbonInterface|null, last_activity: Carbon|null}  $left
     * @param  array{instance: WorkflowInstance, needs_me: bool, deadline: CarbonInterface|null, last_activity: Carbon|null}  $right
     */
    private function compareItems(array $left, array $right): int
    {
        $priorityRank = static fn (WorkflowInstance $instance): int => $instance->submission->priority->rank();
        $leftRank = [
            $left['needs_me'] ? 0 : 1,
            $left['instance']->isOverdue() ? 0 : 1,
            $priorityRank($left['instance']),
            $left['deadline']?->getTimestamp() ?? PHP_INT_MAX,
        ];
        $rightRank = [
            $right['needs_me'] ? 0 : 1,
            $right['instance']->isOverdue() ? 0 : 1,
            $priorityRank($right['instance']),
            $right['deadline']?->getTimestamp() ?? PHP_INT_MAX,
        ];
        $rankComparison = $leftRank <=> $rightRank;

        return $rankComparison !== 0
            ? $rankComparison
            : ($right['last_activity']?->getTimestamp() ?? 0) <=> ($left['last_activity']?->getTimestamp() ?? 0);
    }
}
