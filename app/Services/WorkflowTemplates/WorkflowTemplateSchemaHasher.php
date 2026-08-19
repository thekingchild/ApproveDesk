<?php

namespace App\Services\WorkflowTemplates;

use App\Models\WorkflowTemplateStep;
use Illuminate\Support\Collection;
use JsonException;

final class WorkflowTemplateSchemaHasher
{
    /**
     * @param  Collection<int, WorkflowTemplateStep>  $steps
     *
     * @throws JsonException
     */
    public function hash(Collection $steps): string
    {
        $schema = $steps
            ->sortBy('step_order')
            ->values()
            ->map(function (WorkflowTemplateStep $step): array {
                $assigneeIds = $step->assignee_ids ?? [];
                sort($assigneeIds);

                return [
                    'step_order' => $step->step_order,
                    'name' => $step->name,
                    'step_type' => $step->step_type->value,
                    'quorum_count' => $step->quorum_count,
                    'completion_rule' => $step->groupCompletion()?->value,
                    'assignee_type' => $step->assignee_type->value,
                    'assignee_ids' => $assigneeIds,
                    'assignee_config' => $step->assignee_config,
                    'allow_delegation' => $step->allow_delegation,
                    'conditions' => $step->conditions,
                    'timeout_hours' => $step->timeout_hours,
                    'timeout_action' => $step->timeout_action?->value,
                    'timeout_calendar' => $step->timeoutCalendar()->value,
                ];
            })
            ->all();

        return hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR));
    }
}
