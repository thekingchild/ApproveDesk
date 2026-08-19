<?php

namespace App\Models;

use App\Enums\WorkflowTemplateVersionStatus;
use Database\Factories\WorkflowTemplateVersionFactory;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workflow_template_id
 * @property int $version
 * @property WorkflowTemplateVersionStatus $status
 * @property string|null $schema_hash
 * @property string|null $change_summary
 * @property array<int, array<string, mixed>>|null $outcomes
 * @property int|null $created_by
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkflowTemplate $template
 * @property-read Collection<int, WorkflowTemplateStep> $steps
 */
class WorkflowTemplateVersion extends Model
{
    /** @use HasFactory<WorkflowTemplateVersionFactory> */
    use HasFactory;

    protected $fillable = [
        'workflow_template_id', 'version', 'status', 'schema_hash', 'change_summary', 'outcomes', 'created_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => WorkflowTemplateVersionStatus::class,
            'outcomes' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            $original = $version->getRawOriginal('status');

            if ($original === WorkflowTemplateVersionStatus::Draft->value) {
                return;
            }

            $allowed = ['status'];

            if (collect($version->getDirty())->keys()->diff($allowed)->isNotEmpty()) {
                throw new DomainException('Published workflow template versions are immutable.');
            }

            $from = WorkflowTemplateVersionStatus::from((string) $original);
            $to = $version->status;

            $legal = match ($from) {
                WorkflowTemplateVersionStatus::Published => in_array($to, [
                    WorkflowTemplateVersionStatus::Retired,
                    WorkflowTemplateVersionStatus::Archived,
                ], true),
                WorkflowTemplateVersionStatus::Retired => $to === WorkflowTemplateVersionStatus::Archived,
                default => false,
            };

            if (! $legal) {
                throw new DomainException('This workflow template version cannot change to '.$to->value.'.');
            }
        });

        static::deleting(function (self $version): void {
            if ($version->status->isFrozen()) {
                throw new DomainException('Frozen workflow template versions cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<WorkflowTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<WorkflowTemplateStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowTemplateStep::class, 'template_version_id')
            ->orderBy('step_order');
    }

    /** @return HasMany<WorkflowInstance, $this> */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'template_version_id');
    }
}
