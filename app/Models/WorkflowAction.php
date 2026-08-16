<?php

namespace App\Models;

use App\Enums\WorkflowActionType;
use Database\Factories\WorkflowActionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Immutable audit record. Append-only by design — the model refuses updates
 * and deletes so the audit trail cannot be rewritten through the application.
 *
 * @property int $id
 * @property int $instance_id
 * @property int $step_id
 * @property int $actor_id
 * @property WorkflowActionType $action
 * @property string|null $comment
 * @property int|null $delegated_to
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon|null $created_at
 */
class WorkflowAction extends Model
{
    /** @use HasFactory<WorkflowActionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'instance_id', 'step_id', 'actor_id', 'action', 'comment',
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

    public function instance()
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    public function step()
    {
        return $this->belongsTo(WorkflowTemplateStep::class, 'step_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function delegate()
    {
        return $this->belongsTo(User::class, 'delegated_to');
    }
}
