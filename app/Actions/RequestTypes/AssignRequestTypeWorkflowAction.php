<?php

namespace App\Actions\RequestTypes;

use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AssignRequestTypeWorkflowAction
{
    public function execute(RequestType $requestType, ?int $templateId, User $actor): RequestType
    {
        Gate::forUser($actor)->authorize('assignWorkflow', $requestType);

        if ($templateId !== null) {
            $template = WorkflowTemplate::query()->find($templateId);

            if ($template === null) {
                throw ValidationException::withMessages([
                    'workflowTemplateId' => __('Select an existing workflow template.'),
                ]);
            }

            if (! $template->is_active) {
                throw ValidationException::withMessages([
                    'workflowTemplateId' => __('Assign an active workflow template, or use the default fallback.'),
                ]);
            }
        }

        $requestType->update(['workflow_template_id' => $templateId]);

        return $requestType->refresh();
    }
}
