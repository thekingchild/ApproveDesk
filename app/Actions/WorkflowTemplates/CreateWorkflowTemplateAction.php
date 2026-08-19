<?php

namespace App\Actions\WorkflowTemplates;

use App\DTOs\WorkflowTemplateData;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\DB;

final class CreateWorkflowTemplateAction
{
    public function execute(WorkflowTemplateData $data, User $creator): WorkflowTemplate
    {
        return DB::transaction(function () use ($data, $creator): WorkflowTemplate {
            if ($data->isDefault) {
                WorkflowTemplate::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $template = WorkflowTemplate::create([
                'name' => $data->name,
                'description' => $data->description,
                'submission_types' => $data->isDefault ? null : ($data->submissionTypes ?: null),
                'is_default' => $data->isDefault,
                'is_active' => $data->isActive,
                'created_by' => $creator->id,
            ]);

            foreach ($data->steps as $index => $step) {
                $template->steps()->create([
                    'step_order' => $index + 1,
                    'name' => $step->name,
                    'step_type' => $step->stepType,
                    'quorum_count' => $step->quorumCount,
                    'assignee_type' => $step->assigneeType,
                    'assignee_ids' => $step->assigneeIds,
                    'allow_delegation' => $step->allowDelegation,
                    'conditions' => $step->conditions,
                    'timeout_hours' => $step->timeoutHours,
                    'timeout_action' => $step->timeoutAction,
                ]);
            }

            return $template->load(['steps', 'creator']);
        });
    }
}
