<?php

namespace App\DTOs;

use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;

/** One field of a request-type draft, as SaveRequestTypeDraftAction stores it (RV-ARC-13). */
final readonly class RequestFieldData
{
    /** @param  array<string, mixed>|null  $configuration */
    public function __construct(
        public string $key,
        public string $label,
        public RequestFieldType $type,
        public bool $isRequired = false,
        public bool $isSearchable = false,
        public bool $isFilterable = false,
        public bool $isReportable = false,
        public bool $isSensitive = false,
        public bool $isApproverSummary = false,
        public ?array $configuration = null,
    ) {}

    /**
     * Every flag of a stored field, so a copy keeps search, reporting and
     * privacy settings (a clone used to drop them).
     */
    public static function fromDefinition(RequestFieldDefinition $field): self
    {
        return new self(
            key: $field->key,
            label: $field->label,
            type: $field->type,
            isRequired: $field->is_required,
            isSearchable: $field->is_searchable,
            isFilterable: $field->is_filterable,
            isReportable: $field->is_reportable,
            isSensitive: $field->is_sensitive,
            isApproverSummary: $field->is_approver_summary,
            configuration: $field->configuration,
        );
    }

    /**
     * The storage shape the builder produces (RequestFieldsForm::toStorage()).
     *
     * @param  array<string, mixed>  $field
     */
    public static function fromArray(array $field): self
    {
        return new self(
            key: (string) ($field['key'] ?? ''),
            label: (string) ($field['label'] ?? ''),
            type: RequestFieldType::from((string) ($field['type'] ?? '')),
            isRequired: (bool) ($field['is_required'] ?? false),
            isSearchable: (bool) ($field['is_searchable'] ?? false),
            isFilterable: (bool) ($field['is_filterable'] ?? false),
            isReportable: (bool) ($field['is_reportable'] ?? false),
            isSensitive: (bool) ($field['is_sensitive'] ?? false),
            isApproverSummary: (bool) ($field['is_approver_summary'] ?? false),
            configuration: is_array($field['configuration'] ?? null) ? $field['configuration'] : null,
        );
    }
}
