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

final class CreateWorkflowTemplateAction
{
    public function __construct(private readonly WorkflowTemplateRules $rules) {}

    public function execute(WorkflowTemplateData $data, User $creator): WorkflowTemplate
    {
        $this->rules->assertTemplate($data);
        $this->rules->assertSteps($data->steps);

        StepOutcomes::assertValid($data->outcomes);

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

            $version = $template->versions()->create([
                'version' => 1,
                'status' => WorkflowTemplateVersionStatus::Draft,
                'change_summary' => $data->changeSummary ?: 'Initial version.',
                'outcomes' => $data->outcomes !== [] ? $data->outcomes : null,
                'created_by' => $creator->id,
            ]);

            $this->writeSteps($template, $version, $data->steps);

            app(PublishWorkflowTemplateVersionAction::class)->execute($version);

            return $template->load(['publishedVersion.steps', 'creator']);
        });
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
}
