<?php

namespace App\DTOs;

use App\Enums\AssigneeType;
use App\Enums\StepType;

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
    ) {}
}
