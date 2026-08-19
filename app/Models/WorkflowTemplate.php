<?php

namespace App\Models;

use App\Enums\SubmissionType;
use App\Enums\WorkflowTemplateVersionStatus;
use Database\Factories\WorkflowTemplateFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property SubmissionType[]|null $submission_types
 * @property bool $is_default
 * @property bool $is_active
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, WorkflowTemplateVersion> $versions
 * @property-read WorkflowTemplateVersion|null $publishedVersion
 * @property-read WorkflowTemplateVersion|null $draftVersion
 * @property-read Collection<int, WorkflowTemplateStep> $steps
 * @property-read Collection<int, WorkflowInstance> $instances
 * @property-read Collection<int, RequestType> $requestTypes
 * @property-read User $creator
 */
class WorkflowTemplate extends Model
{
    /** @use HasFactory<WorkflowTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'description', 'submission_types', 'is_default', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'submission_types' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<WorkflowTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowTemplateVersion::class)->orderByDesc('version');
    }

    /** @return HasOne<WorkflowTemplateVersion, $this> */
    public function publishedVersion(): HasOne
    {
        return $this->hasOne(WorkflowTemplateVersion::class)->ofMany(
            ['version' => 'max'],
            function ($query): void {
                $query->where('status', WorkflowTemplateVersionStatus::Published);
            },
        );
    }

    /** @return HasOne<WorkflowTemplateVersion, $this> */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(WorkflowTemplateVersion::class)->ofMany(
            ['version' => 'max'],
            function ($query): void {
                $query->where('status', WorkflowTemplateVersionStatus::Draft);
            },
        );
    }

    /**
     * @return HasMany<WorkflowTemplateStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowTemplateStep::class, 'template_id')
            ->whereHas('version', fn ($query) => $query->where('status', WorkflowTemplateVersionStatus::Published))
            ->orderBy('step_order');
    }

    public function editableVersion(): ?WorkflowTemplateVersion
    {
        return $this->draftVersion ?? $this->publishedVersion;
    }

    /**
     * @return HasMany<WorkflowInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(WorkflowInstance::class, 'template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<RequestType, $this>
     */
    public function requestTypes(): HasMany
    {
        return $this->hasMany(RequestType::class);
    }
}
