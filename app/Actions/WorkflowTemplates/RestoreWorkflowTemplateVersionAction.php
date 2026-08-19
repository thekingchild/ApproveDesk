<?php

namespace App\Actions\WorkflowTemplates;

use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Models\User;
use App\Models\WorkflowTemplateStep;
use App\Models\WorkflowTemplateVersion;

final class RestoreWorkflowTemplateVersionAction
{
    public function execute(WorkflowTemplateVersion $version, User $editor): WorkflowTemplateVersion
    {
        $template = $version->template()->firstOrFail();
        $version->loadMissing('steps');

        abort_if($version->steps->isEmpty(), 422, 'This version has no steps to restore.');

        $data = new WorkflowTemplateData(
            name: $template->name,
            description: $template->description,
            submissionTypes: $template->submission_types ?? [],
            isDefault: $template->is_default,
            isActive: $template->is_active,
            steps: $version->steps->map(fn (WorkflowTemplateStep $step): WorkflowTemplateStepData => new WorkflowTemplateStepData(
                name: $step->name,
                stepType: $step->step_type,
                quorumCount: $step->quorum_count,
                assigneeType: $step->assignee_type,
                assigneeIds: $step->assignee_ids ?? [],
                allowDelegation: $step->allow_delegation,
                conditions: $step->conditions,
                timeoutHours: $step->timeout_hours,
                timeoutAction: $step->timeout_action,
            ))->all(),
            changeSummary: 'Restored from version '.$version->version.'.',
        );

        app(UpdateWorkflowTemplateAction::class)->execute($template, $data, $editor);

        return $template->fresh(['draftVersion', 'publishedVersion'])->draftVersion
            ?? $template->fresh()->publishedVersion;
    }
}
