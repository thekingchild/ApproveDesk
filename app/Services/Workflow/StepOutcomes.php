<?php

namespace App\Services\Workflow;

use App\Enums\OutcomeBehavior;
use App\Enums\WorkflowActionType;
use App\Models\WorkflowTemplateVersion;

final class StepOutcomes
{
    /**
     * @return list<array{key: string, label: string, behavior: string, requires_comment: bool, custom: bool}>
     */
    public function forVersion(?WorkflowTemplateVersion $version): array
    {
        $defaults = [
            [
                'key' => WorkflowActionType::Approve->value,
                'label' => 'Approve',
                'behavior' => OutcomeBehavior::Approve->value,
                'requires_comment' => false,
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::ApproveWithConditions->value,
                'label' => 'Approve with conditions',
                'behavior' => OutcomeBehavior::Approve->value,
                'requires_comment' => true,
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::Reject->value,
                'label' => 'Reject',
                'behavior' => OutcomeBehavior::Reject->value,
                'requires_comment' => true,
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::Return->value,
                'label' => 'Return',
                'behavior' => OutcomeBehavior::Return->value,
                'requires_comment' => true,
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::RequestInformation->value,
                'label' => 'Request information',
                'behavior' => OutcomeBehavior::RequestInformation->value,
                'requires_comment' => true,
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::Escalate->value,
                'label' => 'Escalate',
                'behavior' => OutcomeBehavior::Escalate->value,
                'requires_comment' => true,
                'custom' => false,
            ],
        ];

        $custom = collect($version?->outcomes ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && filled($row['key'] ?? null))
            ->map(fn (array $row): array => [
                'key' => (string) $row['key'],
                'label' => (string) ($row['label'] ?? $row['key']),
                'behavior' => (string) ($row['behavior'] ?? OutcomeBehavior::Approve->value),
                'requires_comment' => (bool) ($row['requires_comment'] ?? true),
                'custom' => true,
            ])
            ->unique('key')
            ->values()
            ->all();

        return [...$defaults, ...$custom];
    }

    /**
     * @return array{key: string, label: string, behavior: string, requires_comment: bool, custom: bool}|null
     */
    public function find(?WorkflowTemplateVersion $version, string $key): ?array
    {
        foreach ($this->forVersion($version) as $outcome) {
            if ($outcome['key'] === $key) {
                return $outcome;
            }
        }

        return null;
    }

    public function actionType(array $outcome): WorkflowActionType
    {
        if ($outcome['key'] === WorkflowActionType::ApproveWithConditions->value) {
            return WorkflowActionType::ApproveWithConditions;
        }

        return OutcomeBehavior::from($outcome['behavior'])->actionType();
    }
}
