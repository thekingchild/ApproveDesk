<?php

namespace App\Models;

use App\Enums\RequestTypeVersionStatus;
use Database\Factories\RequestTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property bool $is_system
 * @property int|null $workflow_template_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkflowTemplate|null $workflowTemplate
 */
class RequestType extends Model
{
    /** @use HasFactory<RequestTypeFactory> */
    use HasFactory;

    public const GENERAL_SLUG = 'general-request';

    protected $fillable = [
        'name', 'slug', 'description', 'is_active', 'is_system', 'workflow_template_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /** @return HasMany<RequestTypeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RequestTypeVersion::class)->orderByDesc('version');
    }

    /** @return HasOne<RequestTypeVersion, $this> */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(RequestTypeVersion::class)
            ->where('status', RequestTypeVersionStatus::Published)
            ->ofMany('version', 'max');
    }

    /** @return BelongsTo<WorkflowTemplate, $this> */
    public function workflowTemplate(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class);
    }

    public function resolvedWorkflowTemplate(): ?WorkflowTemplate
    {
        $assigned = $this->workflowTemplate;

        if ($assigned !== null && $assigned->is_active) {
            return $assigned;
        }

        return WorkflowTemplate::query()
            ->where('is_active', true)
            ->where('is_default', true)
            ->first();
    }
}
