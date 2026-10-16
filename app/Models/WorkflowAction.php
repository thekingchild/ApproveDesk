<?php

namespace App\Models;

use App\Enums\WorkflowActionType;
use Database\Factories\WorkflowActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Immutable audit record. Append-only by design — the model refuses updates
 * and deletes so the audit trail cannot be rewritten through the application.
 *
 * @property int $id
 * @property int $instance_id
 * @property int $step_id
 * @property int|null $actor_id
 * @property int|null $on_behalf_of_id
 * @property WorkflowActionType $action
 * @property string|null $outcome_key
 * @property string|null $comment
 * @property int|null $delegated_to
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 * @property-read WorkflowInstance $instance
 * @property-read WorkflowTemplateStep $step
 * @property-read User|null $actor
 * @property-read User|null $delegate
 * @property-read User|null $onBehalfOf
 */
class WorkflowAction extends Model
{
    /** @use HasFactory<WorkflowActionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'instance_id', 'step_id', 'actor_id', 'on_behalf_of_id', 'action', 'outcome_key', 'comment',
        'delegated_to', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'action' => WorkflowActionType::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('workflow_actions is append-only; updates are forbidden.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('workflow_actions is append-only; deletes are forbidden.');
        });
    }

    /**
     * @return BelongsTo<WorkflowInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    /**
     * @return BelongsTo<WorkflowTemplateStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplateStep::class, 'step_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }

    /** @return BelongsTo<User, $this> */
    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of_id');
    }
}
