<?php

namespace App\DTOs;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\TimeoutAction;

final readonly class WorkflowTemplateStepData
{
    /**
     * @param  array<int, int>  $assigneeIds
     * @param  array<string, mixed>|null  $conditions
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
    ) {}
}
