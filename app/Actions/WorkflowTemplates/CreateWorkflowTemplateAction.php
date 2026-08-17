<?php

namespace App\Actions\WorkflowTemplates;

use App\DTOs\WorkflowTemplateData;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateWorkflowTemplateAction
{
    public function execute(WorkflowTemplateData $data, User $creator): WorkflowTemplate
    {
        return DB::transaction(function () use ($data, $creator): WorkflowTemplate {
            $this->guardTypeCoverage($data);

            if ($data->isDefault) {
                WorkflowTemplate::query()->where('is_default', true)->update(['is_default' => false]);
            }

            $template = WorkflowTemplate::create([
                'name' => $data->name,
                'description' => $data->description,
                'submission_types' => $data->isDefault ? null : $data->submissionTypes,
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
                ]);
            }

            return $template->load(['steps', 'creator']);
        });
    }

    private function guardTypeCoverage(WorkflowTemplateData $data): void
    {
        if ($data->isDefault || ! $data->isActive) {
            return;
        }

        $conflict = WorkflowTemplate::query()
            ->where('is_active', true)
            ->where(function ($query) use ($data): void {
                foreach ($data->submissionTypes as $type) {
                    $query->orWhereJsonContains('submission_types', $type);
                }
            })
            ->exists();

        if ($conflict) {
            throw ValidationException::withMessages([
                'submissionTypes' => __('An active template already handles one or more selected submission types.'),
            ]);
        }
    }
}
