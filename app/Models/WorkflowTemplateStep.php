<?php

namespace App\Models;

use App\Enums\AssigneeType;
use App\Enums\GroupCompletion;
use App\Enums\StepType;
use App\Enums\TimeoutAction;
use App\Enums\TimeoutCalendar;
use App\Enums\WorkflowTemplateVersionStatus;
use App\Services\Workflow\AssigneeResolver;
use Database\Factories\WorkflowTemplateStepFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $template_id
 * @property int|null $template_version_id
 * @property int $step_order
 * @property string $name
 * @property StepType $step_type
 * @property int|null $quorum_count
 * @property GroupCompletion|null $completion_rule
 * @property AssigneeType $assignee_type
 * @property array<int, int> $assignee_ids
 * @property array<string, mixed>|null $assignee_config
 * @property array<string, mixed>|null $conditions
 * @property int|null $timeout_hours
 * @property TimeoutAction|null $timeout_action
 * @property TimeoutCalendar|null $timeout_calendar
 * @property bool $allow_delegation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkflowTemplate $template
 * @property-read WorkflowTemplateVersion|null $version
 */
class WorkflowTemplateStep extends Model
{
    /** @use HasFactory<WorkflowTemplateStepFactory> */
    use HasFactory;

    protected $fillable = [
        'template_id', 'template_version_id', 'step_order', 'name', 'step_type', 'quorum_count',
        'assignee_type', 'assignee_ids', 'assignee_config', 'conditions', 'timeout_hours',
        'timeout_action', 'timeout_calendar', 'allow_delegation', 'completion_rule',
    ];

    protected function casts(): array
    {
        return [
            'step_type' => StepType::class,
            'quorum_count' => 'integer',
            'completion_rule' => GroupCompletion::class,
            'assignee_type' => AssigneeType::class,
            'assignee_ids' => 'array',
            'assignee_config' => 'array',
            'conditions' => 'array',
            'timeout_hours' => 'integer',
            'timeout_action' => TimeoutAction::class,
            'timeout_calendar' => TimeoutCalendar::class,
            'allow_delegation' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $step) => $step->ensureVersionIsMutable($step->template_version_id));
        static::updating(function (self $step): void {
            $step->ensureVersionIsMutable((int) $step->getRawOriginal('template_version_id'));

            if ($step->isDirty('template_version_id')) {
                $step->ensureVersionIsMutable($step->template_version_id);
            }
        });
        static::deleting(fn (self $step) => $step->ensureVersionIsMutable($step->template_version_id));
    }

    /**
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'template_id');
    }

    /** @return BelongsTo<WorkflowTemplateVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplateVersion::class, 'template_version_id');
    }

    private function ensureVersionIsMutable(int|string|null $versionId): void
    {
        if ($versionId === null || $versionId === '') {
            return;
        }

        $status = WorkflowTemplateVersion::query()
            ->whereKey((int) $versionId)
            ->value('status');

        if ($status instanceof WorkflowTemplateVersionStatus && $status->isFrozen()) {
            throw new \DomainException('Steps on frozen workflow template versions are immutable.');
        }

        if ($status === WorkflowTemplateVersionStatus::Published->value
            || $status === WorkflowTemplateVersionStatus::Retired->value
            || $status === WorkflowTemplateVersionStatus::Archived->value) {
            throw new \DomainException('Steps on frozen workflow template versions are immutable.');
        }
    }

    /**
     * Resolve the concrete users assigned to this step.
     *
     * @return Collection<int, User>
     */
    public function assignedUsers(?Submission $submission = null): Collection
    {
        return app(AssigneeResolver::class)->resolve($this, $submission);
    }

    public function groupCompletion(): ?GroupCompletion
    {
        return $this->completion_rule ?? GroupCompletion::fromStepType($this->step_type);
    }

    public function timeoutCalendar(): TimeoutCalendar
    {
        return $this->timeout_calendar ?? TimeoutCalendar::Elapsed;
    }
}
