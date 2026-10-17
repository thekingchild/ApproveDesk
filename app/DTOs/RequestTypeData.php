<?php

namespace App\DTOs;

/** Everything the request-type builder saves in one go (RV-ARC-05). */
final readonly class RequestTypeData
{
    /**
     * @param  array<int, array<string, mixed>>  $fields  in RequestFieldsForm::toStorage() shape
     * @param  array<int, array<string, mixed>>  $selectionRules  as SaveWorkflowSelectionRulesAction takes them
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public array $fields,
        public ?int $workflowTemplateId,
        public array $selectionRules = [],
    ) {}
}
