<?php

namespace App\Livewire\Forms;

use App\Enums\RequestFieldType;
use App\Models\RequestFieldDefinition;
use App\Support\RequestFieldKey;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Editing state for a request type's fields in the builder. Each field is an
 * array of form inputs; this class turns stored definitions into that state
 * and back, keeps field keys unique, and builds unsaved definitions so the
 * schema compiler can check and preview a draft exactly as it will run
 * (RV-ARC-05).
 */
final class RequestFieldsForm
{
    public const MAX_FIELDS = 100;

    /** @return array<string, mixed> */
    public static function blank(): array
    {
        return [
            'label' => '', 'key' => '', 'keyAuto' => true, 'type' => RequestFieldType::ShortText->value, 'is_required' => false,
            'is_searchable' => false, 'is_filterable' => false, 'is_reportable' => false,
            'is_sensitive' => false, 'is_approver_summary' => false,
            'section' => '', 'instructions' => '', 'placeholder' => '', 'help' => '', 'default' => '',
            'maxLength' => '', 'min' => '', 'max' => '', 'options' => '', 'optionsCsv' => '', 'allowedTypes' => '',
            'visibilityField' => '', 'visibilityOperator' => 'equals', 'visibilityValue' => '',
            'requiredField' => '', 'requiredOperator' => 'equals', 'requiredValue' => '', 'calculationFields' => '',
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function rules(): array
    {
        return [
            'fields' => ['required', 'array', 'min:1', 'max:'.self::MAX_FIELDS],
            'fields.*.label' => ['required', 'string', 'max:150'],
            'fields.*.key' => ['required', 'alpha_dash:ascii', 'max:100'],
            'fields.*.type' => ['required', Rule::enum(RequestFieldType::class)],
        ];
    }

    /** @return array<string, mixed> */
    public static function fromDefinition(RequestFieldDefinition $field): array
    {
        $config = $field->configuration ?? [];

        return [...self::blank(),
            'label' => $field->label, 'key' => $field->key, 'keyAuto' => false, 'type' => $field->type->value, 'is_required' => $field->is_required,
            'is_searchable' => $field->is_searchable, 'is_filterable' => $field->is_filterable,
            'is_reportable' => $field->is_reportable, 'is_sensitive' => $field->is_sensitive,
            'is_approver_summary' => $field->is_approver_summary,
            'section' => $config['section'] ?? '', 'instructions' => $config['instructions'] ?? '',
            'placeholder' => $config['placeholder'] ?? '', 'help' => $config['help'] ?? '', 'default' => $config['default'] ?? '',
            'maxLength' => $config['max_length'] ?? '', 'min' => $config['min'] ?? '', 'max' => $config['max'] ?? '',
            'options' => implode("\n", $config['options'] ?? []), 'allowedTypes' => implode(',', $config['allowed_types'] ?? []),
            'visibilityField' => data_get($config, 'visibility.field', ''), 'visibilityOperator' => data_get($config, 'visibility.operator', 'equals'),
            'visibilityValue' => self::valueForEditor(data_get($config, 'visibility.value', '')),
            'requiredField' => data_get($config, 'required_when.field', ''), 'requiredOperator' => data_get($config, 'required_when.operator', 'equals'),
            'requiredValue' => self::valueForEditor(data_get($config, 'required_when.value', '')),
            'calculationFields' => implode(',', data_get($config, 'calculation.fields', [])),
        ];
    }

    /**
     * The shape SaveRequestTypeDraftAction stores.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    public static function toStorage(array $field): array
    {
        $type = (string) ($field['type'] ?? '');
        $isDate = in_array($type, [RequestFieldType::Date->value, RequestFieldType::DateTime->value], true);
        // Date bounds stay strings (YYYY-MM-DD from the picker); numeric bounds
        // are cast to float. Never cast a date bound to a number.
        $bound = fn (mixed $value): string|float|null => self::blankInput($value) ? null : ($isDate ? (string) $value : (float) $value);

        $config = array_filter([
            'section' => self::text($field['section'] ?? ''), 'instructions' => self::text($field['instructions'] ?? ''),
            'placeholder' => self::text($field['placeholder'] ?? ''), 'help' => self::text($field['help'] ?? ''),
            'default' => self::blankInput($field['default'] ?? '') ? null : $field['default'],
            'max_length' => self::blankInput($field['maxLength'] ?? '') ? null : (int) $field['maxLength'],
            'min' => $bound($field['min'] ?? ''),
            'max' => $bound($field['max'] ?? ''),
            'options' => self::options((string) ($field['options'] ?? '')),
            'allowed_types' => self::options((string) ($field['allowedTypes'] ?? '')),
            'visibility' => self::condition($field, 'visibility'),
            'required_when' => self::condition($field, 'required'),
            'calculation' => $type === RequestFieldType::Calculated->value ? ['operation' => 'sum', 'fields' => self::options((string) ($field['calculationFields'] ?? ''))] : null,
        ], fn ($value): bool => $value !== null && $value !== []);

        return [
            'key' => (string) ($field['key'] ?? ''), 'label' => (string) ($field['label'] ?? ''), 'type' => $type,
            'is_required' => (bool) ($field['is_required'] ?? false), 'is_searchable' => (bool) ($field['is_searchable'] ?? false),
            'is_filterable' => (bool) ($field['is_filterable'] ?? false), 'is_reportable' => (bool) ($field['is_reportable'] ?? false),
            'is_sensitive' => (bool) ($field['is_sensitive'] ?? false), 'is_approver_summary' => (bool) ($field['is_approver_summary'] ?? false),
            'configuration' => $config ?: null,
        ];
    }

    /**
     * Unsaved definitions for the schema compiler: draft checks and the
     * preview run the same code as save and the live form. Fields with an
     * unknown type are left out.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return Collection<int, RequestFieldDefinition>
     */
    public static function definitions(array $fields): Collection
    {
        $definitions = collect();

        foreach (array_values($fields) as $position => $field) {
            $type = RequestFieldType::tryFrom((string) ($field['type'] ?? ''));
            if ($type === null) {
                continue;
            }

            $stored = self::toStorage($field);
            $definition = new RequestFieldDefinition;
            $definition->forceFill([
                'key' => $stored['key'],
                'label' => $stored['label'],
                'type' => $type,
                'position' => $position,
                'is_required' => $stored['is_required'],
                'configuration' => $stored['configuration'],
            ]);
            $definitions->put($position, $definition);
        }

        return $definitions;
    }

    /**
     * Fill in keys for fields that follow their label, keeping them unique
     * and pointing rules at the new key.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    public static function withKeys(array $fields): array
    {
        foreach ($fields as $index => $field) {
            $oldKey = trim((string) ($field['key'] ?? ''));
            $auto = (bool) ($field['keyAuto'] ?? $oldKey === '');

            if (! $auto && $oldKey !== '') {
                continue;
            }

            $fields = self::rekeyed($fields, $index, RequestFieldKey::uniqueFromLabel((string) ($field['label'] ?? ''), $fields, $index));
        }

        return $fields;
    }

    /**
     * Give the field at $index a new key and repoint rules that used the old one.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    public static function rekeyed(array $fields, int $index, string $newKey): array
    {
        $oldKey = (string) ($fields[$index]['key'] ?? '');
        $fields[$index]['key'] = $newKey;

        if ($oldKey === '' || $oldKey === $newKey) {
            return $fields;
        }

        foreach ($fields as $other => $field) {
            foreach (['visibilityField', 'requiredField'] as $source) {
                if (($field[$source] ?? '') === $oldKey) {
                    $fields[$other][$source] = $newKey;
                }
            }

            $fields[$other]['calculationFields'] = implode(',', array_map(
                fn (string $key): string => $key === $oldKey ? $newKey : $key,
                self::options((string) ($field['calculationFields'] ?? '')),
            ));
        }

        return $fields;
    }

    /** @return list<string> */
    public static function options(string $options): array
    {
        return array_values(array_unique(array_filter(
            array_map(trim(...), preg_split('/[\r\n,]+/', $options) ?: []),
            fn (string $item): bool => $item !== '',
        )));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array{field: string, operator: string, value: mixed}|null
     */
    private static function condition(array $field, string $prefix): ?array
    {
        $source = (string) ($field[$prefix.'Field'] ?? '');
        if ($source === '') {
            return null;
        }

        $operator = (string) ($field[$prefix.'Operator'] ?? 'equals');
        $value = $field[$prefix.'Value'] ?? '';

        return [
            'field' => $source,
            'operator' => $operator,
            // "In" takes a list; stored as one string it could only ever match that exact text.
            'value' => $operator === 'in' ? self::options(is_array($value) ? implode(',', $value) : (string) $value) : $value,
        ];
    }

    private static function valueForEditor(mixed $value): string
    {
        return is_array($value) ? implode(', ', array_map('strval', $value)) : (string) $value;
    }

    private static function text(mixed $value): ?string
    {
        return self::blankInput($value) ? null : (string) $value;
    }

    private static function blankInput(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
