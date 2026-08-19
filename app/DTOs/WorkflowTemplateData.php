<?php

namespace App\DTOs;

final readonly class WorkflowTemplateData
{
    /**
     * @param  array<int, string>  $submissionTypes
     * @param  array<int, WorkflowTemplateStepData>  $steps
     * @param  array<int, array<string, mixed>>  $outcomes
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $submissionTypes,
        public bool $isDefault,
        public bool $isActive,
        public array $steps,
        public ?string $changeSummary = null,
        public array $outcomes = [],
    ) {}
}
