<?php

namespace App\Services\Workflow;

use App\Enums\OutcomeBehavior;
use App\Enums\WorkflowActionType;
use App\Models\WorkflowTemplateVersion;
use Illuminate\Validation\ValidationException;

final class StepOutcomes
{
    /** Custom outcome keys are rendered into Livewire action expressions; keep them inert. */
    public const KEY_PATTERN = '/^[a-z0-9_]{1,40}$/';

    /**
     * @param  array<int, array<string, mixed>>  $outcomes
     *
     * @throws ValidationException when a custom outcome key or behavior is invalid
     */
    public static function assertValid(array $outcomes): void
    {
        foreach ($outcomes as $outcome) {
            $key = (string) ($outcome['key'] ?? '');

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw ValidationException::withMessages([
                    'outcomes' => __('Outcome keys use 1 to 40 lowercase letters, numbers, or underscores. ":key" is not allowed.', ['key' => $key]),
                ]);
            }

            if (OutcomeBehavior::tryFrom((string) ($outcome['behavior'] ?? '')) === null) {
                throw ValidationException::withMessages(['outcomes' => __('Choose a valid behavior for outcome ":key".', ['key' => $key])]);
            }
        }
    }

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
                'requires_comment' => WorkflowActionType::Approve->requiresComment(),
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::ApproveWithConditions->value,
                'label' => 'Approve with conditions',
                'behavior' => OutcomeBehavior::Approve->value,
                'requires_comment' => WorkflowActionType::ApproveWithConditions->requiresComment(),
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::Reject->value,
                'label' => 'Reject',
                'behavior' => OutcomeBehavior::Reject->value,
                'requires_comment' => WorkflowActionType::Reject->requiresComment(),
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::Return->value,
                'label' => 'Return',
                'behavior' => OutcomeBehavior::Return->value,
                'requires_comment' => WorkflowActionType::Return->requiresComment(),
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::RequestInformation->value,
                'label' => 'Request information',
                'behavior' => OutcomeBehavior::RequestInformation->value,
                'requires_comment' => WorkflowActionType::RequestInformation->requiresComment(),
                'custom' => false,
            ],
            [
                'key' => WorkflowActionType::Escalate->value,
                'label' => 'Escalate',
                'behavior' => OutcomeBehavior::Escalate->value,
                'requires_comment' => WorkflowActionType::Escalate->requiresComment(),
                'custom' => false,
            ],
        ];

        $custom = collect($version?->outcomes ?? [])
            ->filter(fn (mixed $row): bool => is_array($row) && preg_match(self::KEY_PATTERN, (string) ($row['key'] ?? '')) === 1)
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
