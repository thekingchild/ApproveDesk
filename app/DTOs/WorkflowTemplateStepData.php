<?php

namespace App\DTOs;

use App\Enums\AssigneeType;
use App\Enums\GroupCompletion;
use App\Enums\StepType;
use App\Enums\TimeoutAction;
use App\Enums\TimeoutCalendar;

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
}
