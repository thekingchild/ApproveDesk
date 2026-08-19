<?php

namespace App\Models;

use App\Enums\RequestFieldType;
use App\Enums\RequestTypeVersionStatus;
use Database\Factories\RequestFieldDefinitionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $request_type_version_id
 * @property string $key
 * @property string $label
 * @property RequestFieldType $type
 * @property int $position
 * @property bool $is_required
 * @property bool $is_searchable
 * @property bool $is_filterable
 * @property bool $is_reportable
 * @property bool $is_sensitive
 * @property bool $is_approver_summary
 * @property array<string, mixed>|null $configuration
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RequestFieldDefinition extends Model
{
    /** @use HasFactory<RequestFieldDefinitionFactory> */
    use HasFactory;

    protected $fillable = [
        'request_type_version_id', 'key', 'label', 'type', 'position', 'is_required',
        'is_searchable', 'is_filterable', 'is_reportable', 'is_sensitive', 'is_approver_summary', 'configuration',
    ];

    protected function casts(): array
    {
        return [
            'type' => RequestFieldType::class,
            'position' => 'integer',
            'is_required' => 'boolean',
            'is_searchable' => 'boolean',
            'is_filterable' => 'boolean',
            'is_reportable' => 'boolean',
            'is_sensitive' => 'boolean',
            'is_approver_summary' => 'boolean',
            'configuration' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $field) => $field->ensureVersionIsMutable($field->request_type_version_id));
        static::updating(function (self $field): void {
            $field->ensureVersionIsMutable((int) $field->getRawOriginal('request_type_version_id'));

            if ($field->isDirty('request_type_version_id')) {
                $field->ensureVersionIsMutable($field->request_type_version_id);
            }
        });
        static::deleting(fn (self $field) => $field->ensureVersionIsMutable($field->request_type_version_id));
    }

    /** @return BelongsTo<RequestTypeVersion, $this> */
    public function requestTypeVersion(): BelongsTo
    {
        return $this->belongsTo(RequestTypeVersion::class);
    }

    /** @return HasMany<SubmissionFieldValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(SubmissionFieldValue::class);
    }

    private function ensureVersionIsMutable(int $versionId): void
    {
        $status = RequestTypeVersion::query()
            ->whereKey($versionId)
            ->value('status');

        if ($status === RequestTypeVersionStatus::Published || $status === RequestTypeVersionStatus::Published->value) {
            throw new DomainException('Fields on published request type versions are immutable.');
        }
    }
}
