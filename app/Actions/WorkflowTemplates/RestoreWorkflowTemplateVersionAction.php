<?php

namespace App\Actions\WorkflowTemplates;

use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Models\User;
use App\Models\WorkflowTemplateVersion;
use Illuminate\Support\Facades\Gate;

final class RestoreWorkflowTemplateVersionAction
{
    public function execute(WorkflowTemplateVersion $version, User $editor): WorkflowTemplateVersion
    {
        $template = $version->template()->firstOrFail();
        // Restoring rewrites the template's draft: check it here, not only on the page that offers it (ADR 0008, RV-ARC-07).
        Gate::forUser($editor)->authorize('update', $template);
        $version->loadMissing('steps');

        abort_if($version->steps->isEmpty(), 422, 'This version has no steps to restore.');

        $data = new WorkflowTemplateData(
            name: $template->name,
            description: $template->description,
            submissionTypes: $template->submission_types ?? [],
            isDefault: $template->is_default,
            isActive: $template->is_active,
            steps: $version->steps->map(WorkflowTemplateStepData::fromStep(...))->all(),
            changeSummary: 'Restored from version '.$version->version.'.',
            outcomes: $version->outcomes ?? [],
        );

        app(UpdateWorkflowTemplateAction::class)->execute($template, $data, $editor);

        return $template->fresh(['draftVersion', 'publishedVersion'])->draftVersion
            ?? $template->fresh()->publishedVersion;
    }
}
