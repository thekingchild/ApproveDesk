<?php

namespace App\Services\RequestTypes;

use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;
use App\Models\RequestTypeVersion;
use App\Support\RichTextSanitizer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RequestSchemaCompiler
{
    public const CONDITION_OPERATORS = ['equals', 'not_equals', 'in', 'not_empty'];

    public function __construct(private readonly RichTextSanitizer $sanitizer) {}

    /** @param Collection<int, RequestFieldDefinition> $fields */
    public function assertSchemaValid(Collection $fields): void
    {
        $knownKeys = [];
        $knownTypes = [];

        foreach ($fields->sortBy('position')->values() as $index => $field) {
            $configuration = $field->configuration ?? [];

            if (in_array($field->type, [RequestFieldType::Select, RequestFieldType::MultiSelect, RequestFieldType::Radio], true)
                && blank($configuration['options'] ?? [])) {
                throw ValidationException::withMessages(["fields.{$index}.options" => 'Choice fields require at least one option.']);
            }

            foreach (['visibility', 'required_when'] as $ruleName) {
                $condition = $configuration[$ruleName] ?? null;

                if ($condition === null) {
                    continue;
                }

                if (! in_array($condition['operator'] ?? null, self::CONDITION_OPERATORS, true)
                    || ! in_array($condition['field'] ?? null, $knownKeys, true)) {
                    throw ValidationException::withMessages([
                        "fields.{$index}.{$ruleName}" => 'Conditions may only reference an earlier field and an allowed operator.',
                    ]);
                }
            }

            if ($field->type === RequestFieldType::Calculated) {
                $sources = $configuration['calculation']['fields'] ?? [];
                $numericSources = array_keys(array_filter(
                    $knownTypes,
                    fn (RequestFieldType $type): bool => in_array($type, [RequestFieldType::Number, RequestFieldType::Currency, RequestFieldType::Calculated], true),
                ));

                if (($configuration['calculation']['operation'] ?? null) !== 'sum'
                    || $sources === []
                    || array_diff($sources, $numericSources) !== []) {
                    throw ValidationException::withMessages([
                        "fields.{$index}.calculation" => 'Calculated fields support only a sum of earlier numeric fields.',
                    ]);
                }
            }

            if (in_array($field->type, [RequestFieldType::Select, RequestFieldType::MultiSelect, RequestFieldType::Radio], true)
                && isset($configuration['default'])
                && $configuration['default'] !== ''
                && ! in_array($configuration['default'], $configuration['options'] ?? [], true)) {
                throw ValidationException::withMessages([
                    "fields.{$index}.default" => 'The default value must be one of the field options.',
                ]);
            }

            if (in_array($field->type, [RequestFieldType::Date, RequestFieldType::DateTime], true)) {
                $bounds = array_filter([
                    'min' => $configuration['min'] ?? null,
                    'max' => $configuration['max'] ?? null,
                ], fn ($bound): bool => $bound !== null && $bound !== '');

                foreach ($bounds as $boundName => $bound) {
                    if (strtotime((string) $bound) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $bound) !== 1) {
                        throw ValidationException::withMessages([
                            "fields.{$index}.{$boundName}" => __('Date limits must be actual dates in YYYY-MM-DD format, not numbers or free text.'),
                        ]);
                    }
                }

                if (count($bounds) === 2 && strtotime((string) $configuration['min']) > strtotime((string) $configuration['max'])) {
                    throw ValidationException::withMessages([
                        "fields.{$index}.min" => 'The earliest date must not be after the latest date.',
                    ]);
                }
            }

            $knownKeys[] = $field->key;
            $knownTypes[$field->key] = $field->type;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, list<mixed>>
     */
    public function rules(RequestTypeVersion $version, array $values, bool $enforceRequired = true): array
    {
        $version->loadMissing('fields');
        $this->assertSchemaValid($version->fields);
        $values = $this->visibleValues($version, $values);
        $rules = [];

        foreach ($version->fields as $field) {
            if (! $this->isVisible($field, $values) || $field->type === RequestFieldType::Calculated) {
                continue;
            }

            $configuration = $field->configuration ?? [];
            $required = $enforceRequired && $this->isRequired($field, $values);
            $fieldRules = [$required ? 'required' : 'nullable'];

            // A required checkbox means "must be ticked"; false is a value, not a blank (RV-BUG-36).
            if ($required && $field->type === RequestFieldType::Checkbox) {
                $fieldRules[] = 'accepted';
            }

            $fieldRules = [...$fieldRules, ...$this->typeRules($field, $configuration)];
            $rules["values.{$field->key}"] = $fieldRules;

            if ($field->type === RequestFieldType::MultiSelect) {
                $rules["values.{$field->key}.*"] = [Rule::in($configuration['options'] ?? [])];
            }
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function validate(RequestTypeVersion $version, array $values, bool $enforceRequired = true): array
    {
        $rules = $this->rules($version, $values, $enforceRequired);

        return Validator::make(['values' => $this->visibleValues($version, $values)], $rules, [], $this->attributes($version))
            ->validate()['values'] ?? [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function defaults(RequestTypeVersion $version, array $values = []): array
    {
        $version->loadMissing('fields');

        foreach ($version->fields as $field) {
            if (! array_key_exists($field->key, $values) && array_key_exists('default', $field->configuration ?? [])) {
                $values[$field->key] = $field->configuration['default'];
            }
        }

        return $this->withCalculatedValues($version, $values);
    }

    /**
     * Labels of visible required fields that have no value: the submit-time
     * check for paths that never ran the form validation (API, resubmit).
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public function missingRequired(RequestTypeVersion $version, array $values): array
    {
        $values = $this->visibleValues($version, $values);

        return array_values($version->fields
            ->filter(fn (RequestFieldDefinition $field): bool => $field->type !== RequestFieldType::Calculated
                && $this->isVisible($field, $values)
                && $this->isRequired($field, $values)
                && ($field->type === RequestFieldType::Checkbox
                    ? ! filter_var($values[$field->key] ?? false, FILTER_VALIDATE_BOOLEAN)
                    : in_array($values[$field->key] ?? null, [null, '', []], true)))
            ->map(fn (RequestFieldDefinition $field): string => $field->label)
            ->all());
    }

    /**
     * The values the form really holds: a hidden field's answer counts as
     * absent, so a stale value left behind when the user changed an earlier
     * answer cannot show, require or add up anything further down (RV-BUG-29,
     * RV-BUG-30). Conditions may only reference earlier fields, so one pass in
     * field order is transitive. Calculated fields are summed from what is left.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function visibleValues(RequestTypeVersion $version, array $values): array
    {
        return $this->resolveValues($version, $values)['visible'];
    }

    /**
     * Whether the field's own visibility rule matches. Pass visibleValues()
     * so a field under a hidden parent is hidden too.
     *
     * @param  array<string, mixed>  $values
     */
    public function isVisible(RequestFieldDefinition $field, array $values): bool
    {
        $condition = ($field->configuration ?? [])['visibility'] ?? null;

        return $condition === null || $this->conditionMatches($condition, $values);
    }

    /** @param array<string, mixed> $values */
    public function isRequired(RequestFieldDefinition $field, array $values): bool
    {
        $condition = ($field->configuration ?? [])['required_when'] ?? null;

        return $field->is_required || ($condition !== null && $this->conditionMatches($condition, $values));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function withCalculatedValues(RequestTypeVersion $version, array $values): array
    {
        return [...$values, ...$this->resolveValues($version, $values)['calculated']];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array{visible: array<string, mixed>, calculated: array<string, float>}
     */
    private function resolveValues(RequestTypeVersion $version, array $values): array
    {
        $version->loadMissing('fields');
        $calculated = [];

        foreach ($version->fields->sortBy('position') as $field) {
            if ($field->type === RequestFieldType::Calculated) {
                $sources = ($field->configuration ?? [])['calculation']['fields'] ?? [];
                $calculated[$field->key] = $values[$field->key] = array_sum(array_map(
                    fn (string $key): float => (float) ($values[$key] ?? 0),
                    $sources,
                ));
            }

            if (! $this->isVisible($field, $values)) {
                unset($values[$field->key]);
            }
        }

        return ['visible' => $values, 'calculated' => $calculated];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function typedAttributes(RequestFieldDefinition $field, array $values): array
    {
        $value = $values[$field->key] ?? null;

        if ($field->type === RequestFieldType::Calculated) {
            $value = $values[$field->key] ?? 0;
        }

        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if ($field->type === RequestFieldType::RichText) {
            $value = $this->sanitizer->sanitize((string) $value);
        }

        if ($field->type === RequestFieldType::MultiSelect || $field->type === RequestFieldType::File || $field->type === RequestFieldType::Json) {
            $value = array_values((array) $value);
        }

        return [$field->type->valueColumn() => $value];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $values
     */
    private function conditionMatches(array $condition, array $values): bool
    {
        // A multi-select (or file) source holds a list: it "equals" a value when
        // the list contains it, and is "in" a list when any item is (RV-BUG-13).
        $actual = array_map(
            fn (mixed $item): string => is_scalar($item) ? (string) $item : '',
            is_array($values[$condition['field']] ?? null) ? $values[$condition['field']] : [$values[$condition['field']] ?? null],
        );
        $expected = $condition['value'] ?? null;
        $expectedList = array_map(fn (mixed $item): string => is_scalar($item) ? (string) $item : '', (array) $expected);
        $single = is_scalar($expected) ? (string) $expected : '';

        return match ($condition['operator']) {
            'equals' => in_array($single, $actual, true),
            'not_equals' => ! in_array($single, $actual, true),
            'in' => array_intersect($actual, $expectedList) !== [],
            'not_empty' => array_filter($actual, fn (string $item): bool => trim($item) !== '') !== [],
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<mixed>
     */
    private function typeRules(RequestFieldDefinition $field, array $configuration): array
    {
        $max = (int) ($configuration['max_length'] ?? 65535);

        return match ($field->type) {
            RequestFieldType::ShortText => ['string', 'max:'.min($max, 500)],
            RequestFieldType::LongText, RequestFieldType::RichText => ['string', 'max:'.$max],
            RequestFieldType::Number, RequestFieldType::Currency => ['numeric', ...$this->rangeRules($configuration)],
            RequestFieldType::Date => ['date', ...$this->dateRangeRules($configuration)],
            RequestFieldType::DateTime => ['date', ...$this->dateRangeRules($configuration, withTime: true)],
            RequestFieldType::Checkbox => ['boolean'],
            RequestFieldType::Select, RequestFieldType::Radio => [Rule::in($configuration['options'] ?? [])],
            RequestFieldType::MultiSelect => ['array'],
            RequestFieldType::User => ['integer', 'exists:users,id'],
            RequestFieldType::Department => ['integer', 'exists:departments,id'],
            RequestFieldType::Url => ['url', 'max:500'],
            RequestFieldType::Email => ['email', 'max:500'],
            RequestFieldType::File => ['array'],
            RequestFieldType::Json => ['array'],
            RequestFieldType::Calculated => [],
        };
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    private function rangeRules(array $configuration): array
    {
        return array_values(array_filter([
            isset($configuration['min']) ? 'min:'.$configuration['min'] : null,
            isset($configuration['max']) ? 'max:'.$configuration['max'] : null,
        ]));
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return list<string>
     */
    private function dateRangeRules(array $configuration, bool $withTime = false): array
    {
        $max = isset($configuration['max']) && $configuration['max'] !== '' ? (string) $configuration['max'] : null;

        // A date-only latest date includes that whole day for a date-time field;
        // compared as-is it would mean midnight at the start of it (RV-BUG-35).
        if ($withTime && $max !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $max) === 1) {
            $max .= ' 23:59:59';
        }

        return array_values(array_filter([
            isset($configuration['min']) && $configuration['min'] !== '' ? 'after_or_equal:'.$configuration['min'] : null,
            $max !== null ? 'before_or_equal:'.$max : null,
        ]));
    }

    /** @return array<string, string> */
    private function attributes(RequestTypeVersion $version): array
    {
        return $version->fields->mapWithKeys(fn (RequestFieldDefinition $field): array => [
            "values.{$field->key}" => $field->label,
        ])->all();
    }
}
