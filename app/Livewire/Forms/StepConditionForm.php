<?php

namespace App\Livewire\Forms;

use App\Services\SubmissionConditionEvaluator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Editing state for conditional steps, shared by the workflow builder and the
 * Workflow templates page.
 *
 * While a template is edited, branch targets hold the target step's stable
 * key rather than its position, so moving, deleting, or duplicating steps can
 * never silently re-point a branch (RV-BUG-08). Keys become step positions,
 * which is what versions store, only when the template is saved.
 */
final class StepConditionForm
{
    /** @var array<string, string> */
    public const OPERATORS = [
        'equals' => 'Equals',
        'not_equals' => 'Does not equal',
        'in' => 'In comma-separated list',
        'not_in' => 'Not in list',
        'gt' => 'Greater than',
        'gte' => 'At least',
        'lt' => 'Less than',
        'lte' => 'At most',
        'contains' => 'Contains',
        'present' => 'Is present',
    ];

    public const MISSING_TARGET = 'missing';

    public static function newKey(): string
    {
        return 'step-'.Str::lower(Str::random(10));
    }

    /**
     * Form fields for a stored condition. Rules after the first and the match
     * mode are carried through unchanged; the editors show the first rule.
     *
     * @param  array<string, mixed>|null  $conditions
     * @return array<string, mixed>
     */
    public static function state(?array $conditions): array
    {
        $rules = is_array($conditions['rules'] ?? null) ? array_values($conditions['rules']) : [];
        $rule = is_array($rules[0] ?? null) ? $rules[0] : [];
        $value = $rule['value'] ?? '';

        return [
            'conditionField' => (string) ($rule['field'] ?? 'priority'),
            'conditionOperator' => (string) ($rule['operator'] ?? 'equals'),
            'conditionValue' => is_array($value) ? implode(', ', array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', $value)) : (string) $value,
            'conditionMatch' => ($conditions['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'conditionExtraRules' => array_slice($rules, 1),
            'trueStep' => $conditions['true_step'] ?? null,
            'falseStep' => $conditions['false_step'] ?? null,
        ];
    }

    /**
     * Give each step a stable key and turn stored branch positions into keys.
     * Call once, right after loading steps from a version.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    public static function keyed(array $steps): array
    {
        $steps = array_values($steps);
        foreach ($steps as $index => $step) {
            $steps[$index]['key'] = self::newKey();
        }

        foreach ($steps as $index => $step) {
            foreach (['trueStep', 'falseStep'] as $branch) {
                $target = $step[$branch] ?? null;
                $steps[$index][$branch] = match (true) {
                    $target === null || $target === '' => null,
                    is_numeric($target) && isset($steps[(int) $target - 1]) => $steps[(int) $target - 1]['key'],
                    default => self::MISSING_TARGET,
                };
            }
        }

        return $steps;
    }

    /**
     * 1-based position of the step a branch points at, or null for "complete".
     *
     * @param  list<array<string, mixed>>  $steps
     */
    public static function position(array $steps, mixed $key): ?int
    {
        if ($key === null || $key === '') {
            return null;
        }

        foreach ($steps as $index => $step) {
            if (($step['key'] ?? null) === $key) {
                return $index + 1;
            }
        }

        return null;
    }

    /**
     * The stored condition for the step at $index, validated the way the
     * engine will read it.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function conditions(array $steps, int $index): array
    {
        $steps = array_values($steps);
        $step = $steps[$index];
        $operator = (string) (($step['conditionOperator'] ?? '') ?: 'equals');
        $value = (string) ($step['conditionValue'] ?? '');

        $targets = [];
        foreach (['trueStep' => 'true_step', 'falseStep' => 'false_step'] as $branch => $stored) {
            $key = $step[$branch] ?? null;
            $position = self::position($steps, $key);

            if ($key !== null && $key !== '' && $position === null) {
                throw ValidationException::withMessages([
                    "steps.{$index}.{$branch}" => __('This branch pointed at a step that was removed. Choose where it goes now.'),
                ]);
            }

            if ($position !== null && $position <= $index + 1) {
                throw ValidationException::withMessages([
                    "steps.{$index}.{$branch}" => __('A branch must target a later step or completion.'),
                ]);
            }

            $targets[$stored] = $position;
        }

        $conditions = [
            'match' => ($step['conditionMatch'] ?? 'all') === 'any' ? 'any' : 'all',
            'rules' => [
                [
                    'field' => trim((string) (($step['conditionField'] ?? '') ?: 'priority')),
                    'operator' => $operator,
                    'value' => in_array($operator, ['in', 'not_in'], true) ? self::list($value) : $value,
                ],
                ...array_values(is_array($step['conditionExtraRules'] ?? null) ? $step['conditionExtraRules'] : []),
            ],
            ...$targets,
        ];

        try {
            app(SubmissionConditionEvaluator::class)->assertValid($conditions);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                "steps.{$index}.conditionField" => __('Use a request field (priority, type, title, department_id, submitter_id, due_date), a form field as fields.key, or metadata.key.'),
            ]);
        }

        return $conditions;
    }

    /** @return list<string> */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn (string $item): bool => $item !== ''));
    }
}
