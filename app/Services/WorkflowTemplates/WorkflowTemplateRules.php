<?php

namespace App\Services\WorkflowTemplates;

use App\DTOs\WorkflowTemplateData;
use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\GroupCompletion;
use App\Enums\TimeoutAction;
use App\Services\Workflow\AssigneeResolver;
use App\Services\Workflow\WorkflowAssigneeGuard;
use Illuminate\Validation\ValidationException;

/**
 * Save-time rules for a workflow template, enforced by the create and update
 * Actions so the builder, the Workflow templates page, clone and restore all
 * accept the same templates (RV-ARC-06). Publishing adds the whole-graph
 * checks in WorkflowGraphValidator (reachability, cycles).
 */
final class WorkflowTemplateRules
{
    public function __construct(
        private readonly WorkflowAssigneeGuard $assignees,
        private readonly AssigneeResolver $resolver,
    ) {}

    /** @throws ValidationException */
    public function assertTemplate(WorkflowTemplateData $data): void
    {
        if ($data->isDefault && ! $data->isActive) {
            throw ValidationException::withMessages([
                'isActive' => __('The default workflow template must be active.'),
            ]);
        }
    }

    /**
     * Error keys match the editors' fields (steps.N.assigneeIds, …) so each page
     * shows the message next to the step.
     *
     * @param  array<int, WorkflowTemplateStepData>  $steps
     *
     * @throws ValidationException
     */
    public function assertSteps(array $steps): void
    {
        $steps = array_values($steps);
        $this->assignees->assertAssignable($steps);

        foreach ($steps as $index => $step) {
            $this->assertStep($step, $index, count($steps));
        }
    }

    private function assertStep(WorkflowTemplateStepData $step, int $index, int $stepCount): void
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages(["steps.{$index}.{$field}" => $message]);
        $automatic = $step->stepType->isAutomatic();
        $explicit = $step->assigneeType->requiresExplicitAssignees();
        $assigneeCount = 0;

        if (! $automatic && $explicit) {
            $assigneeCount = $this->resolver->candidates($step->toUnsavedStep($index + 1))->count();
            if ($assigneeCount < 1) {
                $fail('assigneeIds', __('Select an assignment with at least one active user.'));
            }
        }

        if (! $automatic && $step->assigneeType === AssigneeType::FormField && trim((string) data_get($step->assigneeConfig, 'field_key', '')) === '') {
            $fail('assigneeField', __('Enter the user field key from the form.'));
        }

        if (! $automatic && $step->assigneeType === AssigneeType::External && trim((string) data_get($step->assigneeConfig, 'resolver', '')) === '') {
            $fail('assigneeResolver', __('Set the external resolver key.'));
        }

        $completion = $step->completionRule ?? GroupCompletion::fromStepType($step->stepType);
        if (! $automatic && $completion === GroupCompletion::Quorum) {
            if ($step->quorumCount === null || $step->quorumCount < 1) {
                $fail('quorumCount', __('Quorum must be at least 1.'));
            }
            if ($explicit && $step->quorumCount > $assigneeCount) {
                $fail('quorumCount', __('Quorum must be between 1 and :count active assignees.', ['count' => $assigneeCount]));
            }
        }

        if (($step->timeoutHours === null) !== ($step->timeoutAction === null)) {
            $fail('timeoutHours', __('Set both an SLA duration and timeout action, or leave both empty.'));
        }

        if ($step->timeoutAction === TimeoutAction::Escalate && $index + 1 >= $stepCount) {
            $fail('timeoutAction', __('The final step cannot escalate because no later step exists.'));
        }
    }
}
