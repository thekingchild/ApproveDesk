<?php

namespace App\Livewire\Forms;

use App\DTOs\WorkflowTemplateStepData;
use App\Enums\AssigneeType;
use App\Enums\GroupCompletion;
use App\Enums\StepType;
use App\Enums\TimeoutAction;
use App\Enums\TimeoutCalendar;
use App\Models\WorkflowTemplateStep;
use App\Models\WorkflowTemplateVersion;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Editing state for a template's steps, shared by the workflow builder and the
 * Workflow templates page so both load, check and save steps the same way
 * (RV-ARC-06). Each step is an array of form fields; conditional branches are
 * handled by StepConditionForm. The rules a saved template must meet are
 * enforced by the create and update Actions (WorkflowTemplateRules).
 */
final class WorkflowStepsForm
{
    public const MAX_STEPS = 20;

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'steps' => ['required', 'array', 'min:1', 'max:'.self::MAX_STEPS],
            'steps.*.name' => ['required', 'string', 'max:255'],
            'steps.*.stepType' => ['required', Rule::enum(StepType::class)],
            'steps.*.quorumCount' => ['nullable', 'integer', 'min:1'],
            'steps.*.completionRule' => ['nullable', 'string', Rule::enum(GroupCompletion::class)],
            'steps.*.completionRuleStepType' => ['nullable', 'string'],
            'steps.*.assigneeType' => ['required', Rule::enum(AssigneeType::class)],
            'steps.*.assigneeIds' => ['array'],
            'steps.*.assigneeIds.*' => ['required', 'integer'],
            'steps.*.assigneeField' => ['nullable', 'string', 'max:255'],
            'steps.*.assigneeResolver' => ['nullable', 'string', 'max:255'],
            'steps.*.allowDelegation' => ['sometimes', 'boolean'],
            'steps.*.conditionField' => ['nullable', 'string', 'max:100'],
            'steps.*.conditionOperator' => ['nullable', Rule::in(array_keys(StepConditionForm::OPERATORS))],
            'steps.*.conditionValue' => ['nullable', 'string', 'max:1000'],
            'steps.*.trueStep' => ['nullable', 'string', 'max:40'],
            'steps.*.falseStep' => ['nullable', 'string', 'max:40'],
            'steps.*.timeoutHours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'steps.*.timeoutAction' => ['nullable', Rule::enum(TimeoutAction::class)],
            'steps.*.timeoutCalendar' => ['nullable', Rule::enum(TimeoutCalendar::class)],
        ];
    }

    /** @return array<string, mixed> */
    public static function blank(int $number, StepType $type = StepType::Sequential, ?string $name = null): array
    {
        return [
            'key' => StepConditionForm::newKey(),
            'name' => $name ?? 'Approval Level '.$number,
            'stepType' => $type->value,
            'quorumCount' => null,
            'completionRule' => GroupCompletion::fromStepType($type)?->value,
            'completionRuleStepType' => $type->value,
            'assigneeType' => AssigneeType::User->value,
            'assigneeIds' => [],
            'assigneeQuery' => '',
            'assigneeField' => '',
            'assigneeResolver' => '',
            'allowDelegation' => true,
            ...StepConditionForm::state(null),
            'timeoutHours' => null,
            'timeoutAction' => '',
            'timeoutCalendar' => TimeoutCalendar::Elapsed->value,
        ];
    }

    /**
     * Form state for a stored version, with stable keys for branch targets.
     *
     * @return list<array<string, mixed>>
     */
    public static function fromVersion(?WorkflowTemplateVersion $version): array
    {
        $steps = ($version->steps ?? collect())->map(fn (WorkflowTemplateStep $step): array => [
            'name' => $step->name,
            'stepType' => $step->step_type->value,
            'quorumCount' => $step->quorum_count,
            // A completion rule belongs to the step type it was chosen for.
            'completionRule' => $step->groupCompletion()?->value,
            'completionRuleStepType' => $step->step_type->value,
            'assigneeType' => $step->assignee_type->value,
            'assigneeIds' => $step->assignee_ids ?? [],
            'assigneeQuery' => '',
            'assigneeField' => (string) data_get($step->assignee_config, 'field_key', ''),
            'assigneeResolver' => (string) data_get($step->assignee_config, 'resolver', ''),
            'allowDelegation' => $step->allow_delegation,
            ...StepConditionForm::state($step->conditions),
            'timeoutHours' => $step->timeout_hours,
            'timeoutAction' => $step->timeout_action->value ?? '',
            'timeoutCalendar' => $step->timeoutCalendar()->value,
        ])->values()->all();

        return StepConditionForm::keyed($steps);
    }

    /**
     * The step type changed: its completion rule goes back to the type's default.
     *
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public static function retyped(array $step): array
    {
        $type = StepType::tryFrom((string) ($step['stepType'] ?? '')) ?? StepType::Sequential;
        $step['completionRule'] = GroupCompletion::fromStepType($type)?->value;
        $step['completionRuleStepType'] = $type->value;

        return $step;
    }

    /**
     * Steps as the Actions take them. Call after validate(self::rules()).
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return list<WorkflowTemplateStepData>
     *
     * @throws ValidationException when a branch or condition is invalid
     */
    public static function toData(array $steps): array
    {
        $steps = array_values($steps);

        return array_map(function (array $step, int $index) use ($steps): WorkflowTemplateStepData {
            $stepType = StepType::from((string) $step['stepType']);
            $type = AssigneeType::from((string) ($step['assigneeType'] ?? AssigneeType::User->value));
            $automatic = $stepType->isAutomatic();

            $chosen = GroupCompletion::tryFrom((string) ($step['completionRule'] ?? ''));
            $chosenForType = ($step['completionRuleStepType'] ?? $stepType->value) === $stepType->value;
            $completion = $automatic ? null : ($chosen !== null && $chosenForType ? $chosen : GroupCompletion::fromStepType($stepType));

            $ids = array_values(array_unique(array_map('intval', $step['assigneeIds'] ?? [])));

            return new WorkflowTemplateStepData(
                name: (string) $step['name'],
                stepType: $stepType,
                quorumCount: $completion === GroupCompletion::Quorum && filled($step['quorumCount'] ?? null) ? (int) $step['quorumCount'] : null,
                assigneeType: $type,
                assigneeIds: $automatic || ! $type->requiresExplicitAssignees() ? [] : $ids,
                allowDelegation: ! $automatic && (bool) ($step['allowDelegation'] ?? true),
                conditions: $stepType === StepType::Conditional ? StepConditionForm::conditions($steps, $index) : null,
                timeoutHours: ! $automatic && filled($step['timeoutHours'] ?? null) ? (int) $step['timeoutHours'] : null,
                timeoutAction: ! $automatic && filled($step['timeoutAction'] ?? null) ? TimeoutAction::from((string) $step['timeoutAction']) : null,
                completionRule: $completion,
                timeoutCalendar: filled($step['timeoutCalendar'] ?? null) ? TimeoutCalendar::from((string) $step['timeoutCalendar']) : TimeoutCalendar::Elapsed,
                assigneeConfig: match ($type) {
                    AssigneeType::FormField => ['field_key' => trim((string) ($step['assigneeField'] ?? ''))],
                    AssigneeType::External => ['resolver' => trim((string) ($step['assigneeResolver'] ?? ''))],
                    default => null,
                },
            );
        }, $steps, array_keys($steps));
    }
}
