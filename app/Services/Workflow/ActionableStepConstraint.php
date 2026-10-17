<?php

namespace App\Services\Workflow;

use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Support\SeparationOfDuties;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * SQL form of WorkflowEngine::canAct() for lists that need the answer for many
 * workflows at once (ADR 0008, RV-ARC-01). ApprovalEligibilityParityTest runs
 * both against the same fixtures; change them together.
 *
 * Known gap: External assignees resolve in PHP only and are not matched here.
 */
final class ActionableStepConstraint
{
    private const DECISIONS = [
        WorkflowActionType::Approve,
        WorkflowActionType::ApproveWithConditions,
        WorkflowActionType::Reject,
        WorkflowActionType::Escalate,
    ];

    public function __construct(private readonly AssignedStepConstraint $assigned) {}

    /**
     * @param  Builder<WorkflowInstance>  $query
     * @return Builder<WorkflowInstance>
     */
    public function constrain(Builder $query, User $user): Builder
    {
        if (! $user->is_active) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('workflow_instances.status', WorkflowStatus::Active->value);

        if (SeparationOfDuties::enabled()) {
            $query->whereHas('submission', fn (Builder $submission) => $submission->where('submitter_id', '!=', $user->id));
        }

        return $query->where(function (Builder $seat) use ($user): void {
            // In their own seat: assigned, not handed to a still-active delegate, not yet decided.
            $seat->where(function (Builder $own) use ($user): void {
                $this->assigned->constrainInstances($own, $user)
                    ->whereDoesntHave('delegations', fn (Builder $delegation) => $this->activeDelegation($delegation)
                        ->where('delegator_id', $user->id)
                        ->whereColumn('delegations.step_id', 'workflow_instances.current_step_id')
                        ->whereHas('delegate', fn (Builder $delegate) => $delegate->where('is_active', true)))
                    ->whereDoesntHave('actions', fn (Builder $actions) => $this->decidedBySeat($actions, $user->id));
            })
            // Filling a delegator's seat: not assigned themselves, and neither seat has decided.
                ->orWhere(function (Builder $delegated) use ($user): void {
                    $delegated->whereNot(fn (Builder $assigned) => $this->assigned->constrainInstances($assigned, $user))
                        ->whereHas('delegations', fn (Builder $delegation) => $this->activeDelegation($delegation)
                            ->where('delegate_id', $user->id)
                            ->whereColumn('delegations.step_id', 'workflow_instances.current_step_id')
                            ->whereNotExists(fn (QueryBuilder $decided) => $this->delegatorDecided($decided)))
                        ->whereDoesntHave('actions', fn (Builder $actions) => $this->decidedBySeat($actions, $user->id));
                });
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $delegation
     * @return Builder<TModel>
     */
    private function activeDelegation(Builder $delegation): Builder
    {
        return $delegation->where('delegations.starts_at', '<=', now())->where('delegations.expires_at', '>', now());
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $actions
     * @return Builder<TModel>
     */
    private function decidedBySeat(Builder $actions, int $seatUserId): Builder
    {
        return ReviewRound::constrain($actions)
            ->whereColumn('workflow_actions.step_id', 'workflow_instances.current_step_id')
            ->whereIn('workflow_actions.action', array_map(fn (WorkflowActionType $type): string => $type->value, self::DECISIONS))
            ->where(fn (Builder $seat) => $seat->where('workflow_actions.on_behalf_of_id', $seatUserId)
                ->orWhere(fn (Builder $own) => $own->whereNull('workflow_actions.on_behalf_of_id')->where('workflow_actions.actor_id', $seatUserId)));
    }

    /** The delegator's seat already has a decision on this step in the current round. */
    private function delegatorDecided(QueryBuilder $decided): void
    {
        $decided->selectRaw('1')
            ->from('workflow_actions as seat_action')
            ->whereColumn('seat_action.instance_id', 'delegations.instance_id')
            ->whereColumn('seat_action.step_id', 'delegations.step_id')
            ->whereIn('seat_action.action', array_map(fn (WorkflowActionType $type): string => $type->value, self::DECISIONS))
            ->where(fn (QueryBuilder $seat) => $seat->whereColumn('seat_action.on_behalf_of_id', 'delegations.delegator_id')
                ->orWhere(fn (QueryBuilder $own) => $own->whereNull('seat_action.on_behalf_of_id')->whereColumn('seat_action.actor_id', 'delegations.delegator_id')))
            ->where('seat_action.id', '>', function (QueryBuilder $roundStart): void {
                $roundStart->selectRaw('coalesce(max(round_start.id), 0)')
                    ->from('workflow_actions as round_start')
                    ->whereColumn('round_start.instance_id', 'seat_action.instance_id')
                    ->whereColumn('round_start.step_id', 'seat_action.step_id')
                    ->where('round_start.action', WorkflowActionType::Return->value);
            });
    }
}
