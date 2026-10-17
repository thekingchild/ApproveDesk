<?php

namespace App\Actions\WorkflowTemplates;

use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Models\User;
use App\Models\WorkflowTemplate;

final class CloneWorkflowTemplateAction
{
    public function execute(WorkflowTemplate $source, User $actor): WorkflowTemplate
    {
        $graph = $source->publishedVersion ?? $source->draftVersion;

        abort_if($graph === null || $graph->steps->isEmpty(), 422, 'This template has no steps to clone.');

        $data = new WorkflowTemplateData(
            name: $this->uniqueName($source->name),
            description: $source->description,
            submissionTypes: $source->submission_types ?? [],
            isDefault: false,
            isActive: true,
            steps: $graph->steps->map(WorkflowTemplateStepData::fromStep(...))->all(),
            changeSummary: 'Cloned from '.$source->name.'.',
            outcomes: $graph->outcomes ?? [],
        );

        return app(CreateWorkflowTemplateAction::class)->execute($data, $actor);
    }

    private function uniqueName(string $name): string
    {
        $base = $name.' copy';
        $candidate = $base;
        $suffix = 2;

        while (WorkflowTemplate::query()->where('name', $candidate)->exists()) {
            $candidate = $base.' '.$suffix++;
        }

        return $candidate;
    }
}
