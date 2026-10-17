<?php

namespace App\Actions\WorkflowTemplates;

use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowSelectionRule;
use App\Models\WorkflowTemplate;
use App\Services\SubmissionConditionEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class SaveWorkflowSelectionRulesAction
{
    public function __construct(private readonly SubmissionConditionEvaluator $conditions) {}

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function execute(RequestType $type, array $rules, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $type);

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

                $conditions = [
                    'match' => 'all',
                    'rules' => [[
                        'field' => $field,
                        'operator' => $operator,
                        'value' => $this->typedValue($operator, $value),
                    ]],
                ];

                // A rule the evaluator refuses would silently never match (RV-BUG-10).
                try {
                    $this->conditions->assertValid($conditions);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages([
                        "rules.{$index}.field" => __('Use a request field (priority, type, title, department_id, submitter_id, due_date), a form field as fields.key, or metadata.key.'),
                    ]);
                }

                WorkflowSelectionRule::query()->create([
                    'request_type_id' => $type->id,
                    'workflow_template_id' => $templateId,
                    'name' => $name,
                    'priority' => $priority,
                    'is_active' => (bool) ($rule['is_active'] ?? true),
                    'conditions' => $conditions,
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
