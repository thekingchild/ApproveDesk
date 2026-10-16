<?php

namespace App\Services;

use App\Models\Submission;
use BackedEnum;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class SubmissionConditionEvaluator
{
    private const FIELDS = [
        'type', 'title', 'priority', 'department_id', 'submitter_id', 'due_date',
    ];

    private const OPERATORS = [
        'equals', 'not_equals', 'in', 'not_in', 'gt', 'gte', 'lt', 'lte', 'contains', 'present',
    ];

    /** @param array<string, mixed> $conditions */
    public function evaluate(Submission $submission, array $conditions): bool
    {
        $this->assertValid($conditions);

        $results = array_map(
            fn (array $rule): bool => $this->evaluateRule($submission, $rule),
            $conditions['rules'],
        );

        return ($conditions['match'] ?? 'all') === 'all'
            ? ! in_array(false, $results, true)
            : in_array(true, $results, true);
    }

    /**
     * Structural check used when a condition is saved or published, so a
     * condition the engine would refuse never reaches a live request (RV-BUG-10).
     *
     * @param  array<string, mixed>  $conditions
     *
     * @throws InvalidArgumentException
     */
    public function assertValid(array $conditions): void
    {
        $rules = $conditions['rules'] ?? null;
        $match = $conditions['match'] ?? 'all';

        if (! is_array($rules) || $rules === [] || ! in_array($match, ['all', 'any'], true)) {
            throw new InvalidArgumentException('Conditions require a non-empty rules array and an all/any match mode.');
        }

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                throw new InvalidArgumentException('Every condition rule must be an object.');
            }

            $field = $rule['field'] ?? null;
            if (! is_string($field) || ! $this->fieldIsAllowed($field)) {
                throw new InvalidArgumentException('Condition field is not allowed.');
            }

            $operator = $rule['operator'] ?? null;
            if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
                throw new InvalidArgumentException('Condition operator is not supported.');
            }
        }
    }

    /** @param array<string, mixed> $rule */
    private function evaluateRule(Submission $submission, array $rule): bool
    {
        $field = (string) $rule['field'];
        $actual = $this->value($submission, $field);
        $expected = $rule['value'] ?? null;

        return match ((string) $rule['operator']) {
            'equals' => $this->comparable($actual) === $this->comparable($expected),
            'not_equals' => $this->comparable($actual) !== $this->comparable($expected),
            'in' => in_array($this->comparable($actual), array_map($this->comparable(...), $this->listValue($expected)), true),
            'not_in' => ! in_array($this->comparable($actual), array_map($this->comparable(...), $this->listValue($expected)), true),
            'gt' => $this->orderedComparison($actual, $expected, fn (int|float|string $a, int|float|string $b): bool => $a > $b),
            'gte' => $this->orderedComparison($actual, $expected, fn (int|float|string $a, int|float|string $b): bool => $a >= $b),
            'lt' => $this->orderedComparison($actual, $expected, fn (int|float|string $a, int|float|string $b): bool => $a < $b),
            'lte' => $this->orderedComparison($actual, $expected, fn (int|float|string $a, int|float|string $b): bool => $a <= $b),
            'contains' => $this->contains($actual, $expected),
            default => $actual !== null && $actual !== '' && $actual !== [],
        };
    }

    /**
     * Lists are stored as arrays; versions saved by the builder before
     * RV-BUG-09 hold "high, urgent" instead, which is read as the same list.
     *
     * @return list<mixed>
     */
    private function listValue(mixed $expected): array
    {
        if (is_array($expected)) {
            return array_values($expected);
        }

        if (! is_string($expected)) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $expected)), fn (string $item): bool => $item !== ''));
    }

    private function fieldIsAllowed(string $field): bool
    {
        return in_array($field, self::FIELDS, true)
            || preg_match('/^metadata(?:\.[A-Za-z0-9_-]+)+$/', $field) === 1
            || preg_match('/^fields\.[A-Za-z0-9_-]+$/', $field) === 1;
    }

    private function value(Submission $submission, string $field): mixed
    {
        if (str_starts_with($field, 'fields.')) {
            return $this->fieldValue($submission, substr($field, 7));
        }

        // Nothing stores request metadata, so metadata.KEY means the form field
        // KEY, as Explain routing and simulation already showed (RV-BUG-11).
        if (str_starts_with($field, 'metadata.')) {
            $path = substr($field, 9);
            $stored = data_get($submission->metadata ?? [], $path);

            return $stored ?? (str_contains($path, '.') ? null : $this->fieldValue($submission, $path));
        }

        $context = [
            'type' => $submission->type,
            'title' => $submission->title,
            'priority' => $submission->priority,
            'department_id' => $submission->department_id,
            'submitter_id' => $submission->submitter_id,
            'due_date' => $submission->due_date,
            'metadata' => $submission->metadata ?? [],
        ];

        return data_get($context, $field);
    }

    private function fieldValue(Submission $submission, string $key): mixed
    {
        $fromSample = data_get($submission->metadata, 'fields.'.$key);
        if ($fromSample !== null) {
            return $fromSample;
        }

        $submission->loadMissing('fieldValues.field');

        return $submission->fieldValues
            ->first(fn ($value): bool => $value->field?->key === $key)
            ?->resolvedValue();
    }

    private function comparable(mixed $value): int|float|string|bool|null
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return is_scalar($value) || $value === null ? $value : null;
    }

    /** @param callable(int|float|string, int|float|string): bool $compare */
    private function orderedComparison(mixed $actual, mixed $expected, callable $compare): bool
    {
        $actual = $this->comparable($actual);
        $expected = $this->comparable($expected);

        if ((! is_int($actual) && ! is_float($actual) && ! is_string($actual))
            || (! is_int($expected) && ! is_float($expected) && ! is_string($expected))) {
            return false;
        }

        return $compare($actual, $expected);
    }

    private function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($this->comparable($expected), array_map($this->comparable(...), $actual), true);
        }

        return is_string($actual) && is_scalar($expected)
            && str_contains(mb_strtolower($actual), mb_strtolower((string) $expected));
    }
}
