<?php

namespace App\DTOs;

use App\Enums\AssigneeType;
use App\Enums\GroupCompletion;
use App\Enums\StepType;
use App\Enums\TimeoutAction;
use App\Enums\TimeoutCalendar;
use App\Models\WorkflowTemplateStep;

final readonly class WorkflowTemplateStepData
{
    /**
     * @param  array<int, int>  $assigneeIds
     * @param  array<string, mixed>|null  $conditions
     * @param  array<string, mixed>|null  $assigneeConfig
     */
    public function __construct(
        public string $name,
        public StepType $stepType,
        public ?int $quorumCount,
        public AssigneeType $assigneeType,
        public array $assigneeIds,
        public bool $allowDelegation,
        public ?array $conditions = null,
        public ?int $timeoutHours = null,
        public ?TimeoutAction $timeoutAction = null,
        public ?GroupCompletion $completionRule = null,
        public ?TimeoutCalendar $timeoutCalendar = null,
        public ?array $assigneeConfig = null,
    ) {}

    /** A stored step as it was saved, for clone and restore. */
    public static function fromStep(WorkflowTemplateStep $step): self
    {
        return new self(
            name: $step->name,
            stepType: $step->step_type,
            quorumCount: $step->quorum_count,
            assigneeType: $step->assignee_type,
            assigneeIds: $step->assignee_ids ?? [],
            allowDelegation: $step->allow_delegation,
            conditions: $step->conditions,
            timeoutHours: $step->timeout_hours,
            timeoutAction: $step->timeout_action,
            completionRule: $step->completion_rule,
            timeoutCalendar: $step->timeout_calendar,
            assigneeConfig: $step->assignee_config,
        );
    }

    /** An unsaved step model at 1-based $order, for simulating or checking a graph before it is saved. */
    public function toUnsavedStep(int $order): WorkflowTemplateStep
    {
        return new WorkflowTemplateStep([
            'step_order' => $order,
            'name' => $this->name,
            'step_type' => $this->stepType,
            'quorum_count' => $this->quorumCount,
            'assignee_type' => $this->assigneeType,
            'assignee_ids' => $this->assigneeIds,
            'assignee_config' => $this->assigneeConfig,
            'allow_delegation' => $this->allowDelegation,
            'conditions' => $this->conditions,
            'timeout_hours' => $this->timeoutHours,
            'timeout_action' => $this->timeoutAction,
            'timeout_calendar' => $this->timeoutCalendar,
            'completion_rule' => $this->completionRule ?? GroupCompletion::fromStepType($this->stepType),
        ]);
    }
}
