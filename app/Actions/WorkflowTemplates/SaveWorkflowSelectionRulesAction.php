<?php

namespace App\Actions\WorkflowTemplates;

use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowSelectionRule;
use App\Models\WorkflowTemplate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SaveWorkflowSelectionRulesAction
{
    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function execute(RequestType $type, array $rules, User $actor): void
    {
        abort_unless($actor->can('update', $type), 403);

        DB::transaction(function () use ($type, $rules): void {
            WorkflowSelectionRule::query()->where('request_type_id', $type->id)->delete();

            foreach (array_values($rules) as $index => $rule) {
                $name = trim((string) ($rule['name'] ?? ''));
                $templateId = (int) ($rule['workflow_template_id'] ?? 0);
                $priority = max(1, (int) ($rule['priority'] ?? ($index + 1)));
                $field = trim((string) ($rule['field'] ?? 'priority'));
                $operator = (string) ($rule['operator'] ?? 'equals');
                $value = $rule['value'] ?? '';

                if ($name === '' || $templateId < 1) {
                    throw ValidationException::withMessages([
                        "rules.{$index}.name" => __('Each rule needs a name and a workflow template.'),
                    ]);
                }

                if (! WorkflowTemplate::query()->whereKey($templateId)->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages([
                        "rules.{$index}.workflow_template_id" => __('Choose an active workflow template.'),
                    ]);
                }

                WorkflowSelectionRule::query()->create([
                    'request_type_id' => $type->id,
                    'workflow_template_id' => $templateId,
                    'name' => $name,
                    'priority' => $priority,
                    'is_active' => (bool) ($rule['is_active'] ?? true),
                    'conditions' => [
                        'match' => 'all',
                        'rules' => [[
                            'field' => $field,
                            'operator' => $operator,
                            'value' => $this->typedValue($operator, $value),
                        ]],
                    ],
                ]);
            }
        });
    }

    private function typedValue(string $operator, mixed $value): mixed
    {
        if (in_array($operator, ['in', 'not_in'], true)) {
            return collect(preg_split('/\s*,\s*/', (string) $value) ?: [])->filter()->values()->all();
        }

        if (is_numeric($value)) {
            return $value + 0;
        }

        return $value;
    }
}
