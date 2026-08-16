<?php

namespace App\Models;

use App\Enums\SubmissionType;
use Database\Factories\WorkflowTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function steps()
    {
        return $this->hasMany(WorkflowTemplateStep::class, 'template_id')
            ->orderBy('step_order');
    }

    public function instances()
    {
        return $this->hasMany(WorkflowInstance::class, 'template_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
