<?php

namespace App\Models;

use App\Enums\AssigneeType;
use App\Enums\StepType;
use App\Enums\TimeoutAction;
use Database\Factories\WorkflowTemplateStepFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * @property int $id
 * @property int $template_id
 * @property int $step_order
 * @property string $name
 * @property StepType $step_type
 * @property int|null $quorum_count
 * @property AssigneeType $assignee_type
 * @property array<int, int> $assignee_ids
 * @property array<string, mixed>|null $conditions
 * @property int|null $timeout_hours
 * @property TimeoutAction|null $timeout_action
 * @property bool $allow_delegation
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkflowTemplate $template
 */
class WorkflowTemplateStep extends Model
{
    /** @use HasFactory<WorkflowTemplateStepFactory> */
    use HasFactory;

    protected $fillable = [
        'template_id', 'step_order', 'name', 'step_type', 'quorum_count',
        'assignee_type', 'assignee_ids', 'conditions', 'timeout_hours',
        'timeout_action', 'allow_delegation',
    ];

    protected function casts(): array
    {
        return [
            'step_type' => StepType::class,
            'quorum_count' => 'integer',
            'assignee_type' => AssigneeType::class,
            'assignee_ids' => 'array',
            'conditions' => 'array',
            'timeout_hours' => 'integer',
            'timeout_action' => TimeoutAction::class,
            'allow_delegation' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<WorkflowTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'template_id');
    }

    /**
     * Resolve the concrete users assigned to this step, expanding role and
     * department assignees into their member users.
     *
     * @return Collection<int, User>
     */
    public function assignedUsers(): Collection
    {
        $ids = $this->assignee_ids ?? [];

        return match ($this->assignee_type) {
            AssigneeType::User => User::whereIn('id', $ids)->get(),
            AssigneeType::Role => User::role($this->resolveRoleNames($ids))->get(),
            AssigneeType::Department => User::whereIn('department_id', $ids)->get(),
        };
    }

    /**
     * @param  array<int, int>  $roleIds
     * @return array<int, string>
     */
    private function resolveRoleNames(array $roleIds): array
    {
        return Role::whereIn('id', $roleIds)->pluck('name')->all();
    }
}
