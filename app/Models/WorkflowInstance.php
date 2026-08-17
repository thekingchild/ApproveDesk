<?php

namespace App\Models;

use App\Enums\WorkflowStatus;
use Database\Factories\WorkflowInstanceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $submission_id
 * @property int $template_id
 * @property int|null $current_step_id
 * @property WorkflowStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Submission $submission
 * @property-read WorkflowTemplate $template
 * @property-read WorkflowTemplateStep|null $currentStep
 * @property-read Collection<int, WorkflowAction> $actions
 */
class WorkflowInstance extends Model
{
    /** @use HasFactory<WorkflowInstanceFactory> */
    use HasFactory;

    /** In-memory transition flags set by the WorkflowEngine — never persisted. */
    public bool $wasCompleted = false;

    public bool $wasRejected = false;

    public ?WorkflowTemplateStep $advancedToStep = null;

    protected $fillable = [
        'submission_id', 'template_id', 'current_step_id', 'status', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'template_id');
    }

    /**
     * @return BelongsTo<WorkflowTemplateStep, $this>
     */
    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplateStep::class, 'current_step_id');
    }

    /**
     * @return HasMany<WorkflowAction, $this>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class, 'instance_id')->orderBy('created_at')->orderBy('id');
    }

    /** @return HasMany<Delegation, $this> */
    public function delegations(): HasMany
    {
        return $this->hasMany(Delegation::class, 'instance_id');
    }
}
