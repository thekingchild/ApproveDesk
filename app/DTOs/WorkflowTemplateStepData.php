<?php

namespace App\DTOs;

use App\Enums\AssigneeType;

final readonly class WorkflowTemplateStepData
{
    /** @param array<int, int> $assigneeIds */
    public function __construct(
        public string $name,
        public AssigneeType $assigneeType,
        public array $assigneeIds,
    ) {}
}
