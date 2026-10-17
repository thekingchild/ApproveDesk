<?php

namespace App\Models;

use Database\Factories\WorkflowSelectionRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $request_type_id
 * @property int $workflow_template_id
 * @property string $name
 * @property int $priority
 * @property bool $is_active
 * @property array<string, mixed> $conditions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowSelectionRule extends Model
{
    /** @use HasFactory<WorkflowSelectionRuleFactory> */
    use HasFactory;

    protected $fillable = [
        'request_type_id', 'workflow_template_id', 'name', 'priority', 'is_active', 'conditions',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'conditions' => 'array',
        ];
    }

    /** @return BelongsTo<RequestType, $this> */
    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }

    /** @return BelongsTo<WorkflowTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }
}
