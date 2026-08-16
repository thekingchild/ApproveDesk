<?php

namespace App\Models;

use App\Enums\WorkflowStatus;
use Database\Factories\WorkflowInstanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
 */
class WorkflowInstance extends Model
{
    /** @use HasFactory<WorkflowInstanceFactory> */
    use HasFactory;

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

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function template()
    {
        return $this->belongsTo(WorkflowTemplate::class, 'template_id');
    }

    public function currentStep()
    {
        return $this->belongsTo(WorkflowTemplateStep::class, 'current_step_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(WorkflowAction::class, 'instance_id')->orderBy('created_at')->orderBy('id');
    }
}
