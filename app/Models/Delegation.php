<?php

namespace App\Models;

use Database\Factories\DelegationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A temporary authorization grant for one workflow step. Expired grants may
 * be purged because the append-only WorkflowAction remains the audit record.
 *
 * @property int $id
 * @property int $instance_id
 * @property int $step_id
 * @property int $delegator_id
 * @property int $delegate_id
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property-read WorkflowInstance $instance
 * @property-read WorkflowTemplateStep $step
 * @property-read User $delegator
 * @property-read User $delegate
 */
class Delegation extends Model
{
    /** @use HasFactory<DelegationFactory> */
    use HasFactory;

    protected $fillable = [
        'instance_id', 'step_id', 'delegator_id', 'delegate_id',
        'starts_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @param Builder<Delegation> $query */
    public function scopeActive(Builder $query, ?Carbon $at = null): void
    {
        $at ??= now();

        $query->where('starts_at', '<=', $at)->where('expires_at', '>', $at);
    }

    /** @return BelongsTo<WorkflowInstance, $this> */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(WorkflowInstance::class, 'instance_id');
    }

    /** @return BelongsTo<WorkflowTemplateStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplateStep::class, 'step_id');
    }

    /** @return BelongsTo<User, $this> */
    public function delegator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegator_id');
    }

    /** @return BelongsTo<User, $this> */
    public function delegate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delegate_id');
    }
}
