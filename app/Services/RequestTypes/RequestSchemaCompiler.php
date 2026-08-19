<?php

namespace App\Services\RequestTypes;

use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;
use App\Models\RequestTypeVersion;
use App\Services\RichTextSanitizer;
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
        $rules = [];

        foreach ($version->fields as $field) {
            if (! $this->isVisible($field, $values) || $field->type === RequestFieldType::Calculated) {
                continue;
            }

            $configuration = $field->configuration ?? [];
            $fieldRules = [$enforceRequired && $this->isRequired($field, $values) ? 'required' : 'nullable'];
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
        return Validator::make(['values' => $values], $this->rules($version, $values, $enforceRequired), [], $this->attributes($version))
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

    /** @param array<string, mixed> $values */
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
        foreach ($version->fields as $field) {
            if ($field->type !== RequestFieldType::Calculated) {
                continue;
            }

            $sources = ($field->configuration ?? [])['calculation']['fields'] ?? [];
            $values[$field->key] = array_sum(array_map(fn (string $key): float => (float) ($values[$key] ?? 0), $sources));
        }

        return $values;
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
        $actual = $values[$condition['field']] ?? null;
        $expected = $condition['value'] ?? null;

        return match ($condition['operator']) {
            'equals' => (string) $actual === (string) $expected,
            'not_equals' => (string) $actual !== (string) $expected,
            'in' => in_array((string) $actual, array_map('strval', (array) $expected), true),
            'not_empty' => ! blank($actual),
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
            RequestFieldType::Date => ['date'],
            RequestFieldType::DateTime => ['date'],
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

    /** @return array<string, string> */
    private function attributes(RequestTypeVersion $version): array
    {
        return $version->fields->mapWithKeys(fn (RequestFieldDefinition $field): array => [
            "values.{$field->key}" => $field->label,
        ])->all();
    }
}
