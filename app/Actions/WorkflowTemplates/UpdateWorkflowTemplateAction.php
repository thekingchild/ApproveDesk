<?php

namespace App\Actions\WorkflowTemplates;

use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\GroupCompletion;
use App\Enums\WorkflowTemplateVersionStatus;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateVersion;
use App\Services\Workflow\StepOutcomes;
use App\Services\WorkflowTemplates\WorkflowTemplateRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateWorkflowTemplateAction
{
    public function __construct(private readonly WorkflowTemplateRules $rules) {}

    /**
     * @param  string|null  $expectedToken  WorkflowTemplate::editionToken() as the editor
     *                                      loaded it; a mismatch means someone else saved since (RV-BUG-37)
     */
    public function execute(WorkflowTemplate $template, WorkflowTemplateData $data, User $editor, ?string $expectedToken = null): WorkflowTemplate
    {
        $this->rules->assertTemplate($data);

        return DB::transaction(function () use ($template, $data, $editor, $expectedToken): WorkflowTemplate {
            /** @var WorkflowTemplate $template */
            $template = WorkflowTemplate::query()
                ->with(['publishedVersion.steps', 'draftVersion.steps'])
                ->whereKey($template->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($expectedToken !== null && ! hash_equals($template->editionToken(), $expectedToken)) {
                throw ValidationException::withMessages([
                    'template' => __('Another administrator changed this workflow after you opened it. Reload the page to see their version, then make your changes again.'),
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
                'submission_types' => $data->isDefault ? null : ($data->submissionTypes ?: null),
                'is_default' => $data->isDefault,
                'is_active' => $data->isActive,
            ]);

            $baseline = $template->draftVersion ?? $template->publishedVersion;

            if ($baseline === null || $this->structureChanged($baseline, $data)) {
                $this->rules->assertSteps($data->steps);
                $draft = $this->editableDraft($template, $editor, $data->changeSummary);
                StepOutcomes::assertValid($data->outcomes);
                $draft->forceFill(['outcomes' => $data->outcomes !== [] ? $data->outcomes : null])->save();
                $draft->steps()->delete();
                $this->writeSteps($template, $draft, $data->steps);

                if (! $template->instances()->exists()) {
                    app(PublishWorkflowTemplateVersionAction::class)->execute($draft->fresh('steps'), $data->changeSummary);
                }
            }

            return $template->load(['publishedVersion.steps', 'draftVersion.steps', 'creator']);
        });
    }

    private function editableDraft(WorkflowTemplate $template, User $editor, ?string $changeSummary): WorkflowTemplateVersion
    {
        if ($template->draftVersion !== null) {
            if ($changeSummary) {
                $template->draftVersion->update(['change_summary' => $changeSummary]);
            }

            return $template->draftVersion;
        }

        return $template->versions()->create([
            'version' => ((int) $template->versions()->max('version')) + 1,
            'status' => WorkflowTemplateVersionStatus::Draft,
            'change_summary' => $changeSummary,
            'created_by' => $editor->id,
        ]);
    }

    /**
     * @param  array<int, WorkflowTemplateStepData>  $steps
     */
    private function writeSteps(WorkflowTemplate $template, WorkflowTemplateVersion $version, array $steps): void
    {
        foreach ($steps as $index => $step) {
            $version->steps()->create([
                'template_id' => $template->id,
                'step_order' => $index + 1,
                'name' => $step->name,
                'step_type' => $step->stepType,
                'quorum_count' => $step->quorumCount,
                'assignee_type' => $step->assigneeType,
                'assignee_ids' => $step->assigneeIds,
                'assignee_config' => $step->assigneeConfig,
                'allow_delegation' => $step->allowDelegation,
                'conditions' => $step->conditions,
                'timeout_hours' => $step->timeoutHours,
                'timeout_action' => $step->timeoutAction,
                'timeout_calendar' => $step->timeoutCalendar,
                'completion_rule' => $step->completionRule ?? GroupCompletion::fromStepType($step->stepType),
            ]);
        }
    }

    private function structureChanged(WorkflowTemplateVersion $version, WorkflowTemplateData $data): bool
    {
        $current = $version->steps->map(fn ($step) => [
            'name' => $step->name,
            'step_type' => $step->step_type->value,
            'quorum_count' => $step->quorum_count,
            'assignee_type' => $step->assignee_type->value,
            'assignee_ids' => $this->sortedIds($step->assignee_ids),
            'assignee_config' => $step->assignee_config,
            'allow_delegation' => $step->allow_delegation,
            'conditions' => $step->conditions,
            'timeout_hours' => $step->timeout_hours,
            'timeout_action' => $step->timeout_action?->value,
            'timeout_calendar' => $step->timeout_calendar?->value,
            'completion_rule' => $step->groupCompletion()?->value,
        ])->values()->all();

        $incoming = collect($data->steps)->map(fn ($step) => [
            'name' => $step->name,
            'step_type' => $step->stepType->value,
            'quorum_count' => $step->quorumCount,
            'assignee_type' => $step->assigneeType->value,
            'assignee_ids' => $this->sortedIds($step->assigneeIds),
            'assignee_config' => $step->assigneeConfig,
            'allow_delegation' => $step->allowDelegation,
            'conditions' => $step->conditions,
            'timeout_hours' => $step->timeoutHours,
            'timeout_action' => $step->timeoutAction?->value,
            'timeout_calendar' => $step->timeoutCalendar?->value,
            'completion_rule' => ($step->completionRule ?? GroupCompletion::fromStepType($step->stepType))?->value,
        ])->values()->all();

        return $current !== $incoming || ($version->outcomes ?? []) !== $data->outcomes;
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
}
