<?php

namespace App\Models;

use Database\Factories\SubmissionFieldValueFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $date_value
 * @property Carbon|null $datetime_value
 */
class SubmissionFieldValue extends Model
{
    /** @use HasFactory<SubmissionFieldValueFactory> */
    use HasFactory;

    public const VALUE_COLUMNS = [
        'string_value',
        'text_value',
        'decimal_value',
        'date_value',
        'datetime_value',
        'boolean_value',
        'user_value_id',
        'department_value_id',
        'json_value',
    ];

    protected $fillable = [
        'submission_id', 'request_field_definition_id', ...self::VALUE_COLUMNS,
    ];

    protected function casts(): array
    {
        return [
            'decimal_value' => 'decimal:6',
            'date_value' => 'date',
            'datetime_value' => 'datetime',
            'boolean_value' => 'boolean',
            'json_value' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $value): void {
            $value->ensureDefinitionMatchesSubmissionVersion();
            $value->ensureCorrectTypedColumn();
        });
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<RequestFieldDefinition, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(RequestFieldDefinition::class, 'request_field_definition_id');
    }

    /** @return BelongsTo<User, $this> */
    public function userValue(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_value_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function departmentValue(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_value_id');
    }

    public function resolvedValue(): mixed
    {
        return match ($this->field->type->valueColumn()) {
            'date_value' => $this->date_value?->toDateString(),
            'datetime_value' => $this->datetime_value?->format('Y-m-d\TH:i'),
            default => $this->getAttribute($this->field->type->valueColumn()),
        };
    }

    private function ensureDefinitionMatchesSubmissionVersion(): void
    {
        $submissionVersionId = Submission::query()
            ->whereKey($this->submission_id)
            ->value('request_type_version_id');
        $definitionVersionId = RequestFieldDefinition::query()
            ->whereKey($this->request_field_definition_id)
            ->value('request_type_version_id');

        if ($submissionVersionId === null || $definitionVersionId === null || (int) $submissionVersionId !== (int) $definitionVersionId) {
            throw new DomainException('Submission field values must use a field from the submission request type version.');
        }
    }

    private function ensureCorrectTypedColumn(): void
    {
        $field = RequestFieldDefinition::query()->findOrFail($this->request_field_definition_id);
        $populated = collect(self::VALUE_COLUMNS)
            ->filter(fn (string $column): bool => $this->getAttribute($column) !== null)
            ->values();

        if ($populated->count() !== 1 || $populated->first() !== $field->type->valueColumn()) {
            throw new DomainException("The {$field->type->value} field must use {$field->type->valueColumn()} exclusively.");
        }
    }
}
