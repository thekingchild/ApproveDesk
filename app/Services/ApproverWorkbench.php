<?php

namespace App\Services;

use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\User;
use App\Models\WorkflowInstance;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ApproverWorkbench
{
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
        $instances = WorkflowInstance::query()
            ->with([
                'submission.submitter.department',
                'submission.department',
                'submission.files',
                'currentStep',
                'actions',
                'delegations',
            ])
            ->where(function ($query) use ($user): void {
                $query->whereHas('actions', fn ($actions) => $actions->where('actor_id', $user->id))
                    ->orWhere('status', WorkflowStatus::Active);
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
    }

    public function canAct(WorkflowInstance $instance, User $user): bool
    {
        $instance->loadMissing(['currentStep', 'actions', 'delegations']);

        return $instance->status === WorkflowStatus::Active
            && $this->eligibility($instance, $user)['eligible']
            && ! $this->hasDecidedCurrentStep($instance, $user);
    }

    /** @return array{eligible: bool, delegated_to_me: bool, delegated_by_me: bool} */
    private function eligibility(WorkflowInstance $instance, User $user): array
    {
        $activeDelegations = $instance->delegations->filter(fn ($delegation) => $delegation->step_id === $instance->current_step_id
            && $delegation->starts_at <= now()
            && $delegation->expires_at > now());
        $delegatedToUser = $activeDelegations->contains('delegate_id', $user->id);
        $delegatedByUser = $activeDelegations->contains('delegator_id', $user->id);
        $direct = $instance->currentStep?->assignedUsers($instance->submission)->contains($user) ?? false;

        return [
            'eligible' => $delegatedToUser || ($direct && ! $delegatedByUser),
            'delegated_to_me' => $delegatedToUser,
            'delegated_by_me' => $delegatedByUser,
        ];
    }

    private function hasDecidedCurrentStep(WorkflowInstance $instance, User $user): bool
    {
        return $instance->actions
            ->where('step_id', $instance->current_step_id)
            ->where('actor_id', $user->id)
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
        $priorityRank = static fn (WorkflowInstance $instance): int => match ($instance->submission->priority->value) {
            'urgent' => 0,
            'high' => 1,
            'normal' => 2,
            default => 3,
        };
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
