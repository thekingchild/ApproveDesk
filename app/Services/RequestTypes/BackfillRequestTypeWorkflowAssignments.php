<?php

namespace App\Services\RequestTypes;

use App\Enums\SubmissionType;
use App\Models\RequestType;
use App\Models\WorkflowTemplate;

final class BackfillRequestTypeWorkflowAssignments
{
    public function execute(): void
    {
        $defaultId = WorkflowTemplate::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->value('id');

        RequestType::query()
            ->whereNull('workflow_template_id')
            ->orderBy('id')
            ->each(function (RequestType $type) use ($defaultId): void {
                $templateId = $this->legacyMatch($type) ?? $defaultId;

                if ($templateId !== null) {
                    $type->update(['workflow_template_id' => $templateId]);
                }
            });
    }

    private function legacyMatch(RequestType $type): ?int
    {
        if ($type->slug === RequestType::GENERAL_SLUG) {
            return null;
        }

        return WorkflowTemplate::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->whereJsonContains('submission_types', SubmissionType::Request->value)
            ->value('id');
    }
}
