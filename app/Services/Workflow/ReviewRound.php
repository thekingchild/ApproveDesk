<?php

namespace App\Services\Workflow;

use App\Enums\WorkflowActionType;
use App\Models\WorkflowAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

/**
 * A "Return for revision" on a step starts a new review round for that step:
 * decisions recorded before the latest return no longer count toward the
 * step, so every approver reviews the revised request (RV-ENG-04). Earlier
 * actions stay in the append-only history.
 */
final class ReviewRound
{
    /**
     * Restrict a workflow_actions query to the current round of each row's own
     * instance and step (works inside whereHas on instances, too).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrain(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where("{$table}.id", '>', function (QueryBuilder $latestReturn) use ($table): void {
            $latestReturn->selectRaw('coalesce(max(round_start.id), 0)')
                ->from('workflow_actions as round_start')
                ->whereColumn('round_start.instance_id', "{$table}.instance_id")
                ->whereColumn('round_start.step_id', "{$table}.step_id")
                ->where('round_start.action', WorkflowActionType::Return->value);
        });
    }

    /**
     * Filter already-loaded actions of one instance to the current round of a step.
     *
     * @param  Collection<int, WorkflowAction>  $actions
     * @return Collection<int, WorkflowAction>
     */
    public static function filter(Collection $actions, ?int $stepId): Collection
    {
        $stepActions = $actions->where('step_id', $stepId);
        $roundStart = (int) $stepActions
            ->filter(fn (WorkflowAction $action): bool => $action->action === WorkflowActionType::Return)
            ->max('id');

        return $stepActions->filter(fn (WorkflowAction $action): bool => $action->id > $roundStart)->values();
    }
}
