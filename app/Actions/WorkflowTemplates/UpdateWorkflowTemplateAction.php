<?php

namespace App\Actions\WorkflowTemplates;

use App\DTOs\WorkflowTemplateData;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateWorkflowTemplateAction
{
    public function execute(WorkflowTemplate $template, WorkflowTemplateData $data): WorkflowTemplate
    {
        return DB::transaction(function () use ($template, $data): WorkflowTemplate {
            /** @var WorkflowTemplate $template */
            $template = WorkflowTemplate::query()
                ->with('steps')
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardTypeCoverage($template, $data);
            $structureChanged = $this->structureChanged($template, $data);

            if ($structureChanged && $template->instances()->exists()) {
                throw ValidationException::withMessages([
                    'steps' => __('Approval steps cannot be changed after this template has been used.'),
                ]);
            }

            if ($data->isDefault) {
                WorkflowTemplate::query()
                    ->whereKeyNot($template->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $template->update([
                'name' => $data->name,
                'description' => $data->description,
                'submission_types' => $data->isDefault ? null : $data->submissionTypes,
                'is_default' => $data->isDefault,
                'is_active' => $data->isActive,
            ]);

            if ($structureChanged) {
                $template->steps()->delete();

                foreach ($data->steps as $index => $step) {
                    $template->steps()->create([
                        'step_order' => $index + 1,
                        'name' => $step->name,
                        'step_type' => $step->stepType,
                        'quorum_count' => $step->quorumCount,
                        'assignee_type' => $step->assigneeType,
                        'assignee_ids' => $step->assigneeIds,
                        'allow_delegation' => $step->allowDelegation,
                    ]);
                }
            }

            return $template->load(['steps', 'creator']);
        });
    }

    private function structureChanged(WorkflowTemplate $template, WorkflowTemplateData $data): bool
    {
        $current = $template->steps->map(fn ($step) => [
            'name' => $step->name,
            'step_type' => $step->step_type->value,
            'quorum_count' => $step->quorum_count,
            'assignee_type' => $step->assignee_type->value,
            'assignee_ids' => $this->sortedIds($step->assignee_ids),
            'allow_delegation' => $step->allow_delegation,
        ])->values()->all();

        $incoming = collect($data->steps)->map(fn ($step) => [
            'name' => $step->name,
            'step_type' => $step->stepType->value,
            'quorum_count' => $step->quorumCount,
            'assignee_type' => $step->assigneeType->value,
            'assignee_ids' => $this->sortedIds($step->assigneeIds),
            'allow_delegation' => $step->allowDelegation,
        ])->values()->all();

        return $current !== $incoming;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, int>
     */
    private function sortedIds(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    private function guardTypeCoverage(WorkflowTemplate $template, WorkflowTemplateData $data): void
    {
        if ($data->isDefault || ! $data->isActive) {
            return;
        }

        $conflict = WorkflowTemplate::query()
            ->whereKeyNot($template->getKey())
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
