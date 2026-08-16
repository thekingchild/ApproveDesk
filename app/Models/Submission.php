<?php

namespace App\Models;

use App\Enums\SubmissionPriority;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Services\ReferenceNumberGenerator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reference_number
 * @property SubmissionType $type
 * @property string $title
 * @property string|null $body
 * @property int $submitter_id
 * @property int|null $department_id
 * @property SubmissionPriority $priority
 * @property Carbon|null $due_date
 * @property SubmissionStatus $status
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Submission extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference_number', 'type', 'title', 'body', 'submitter_id',
        'department_id', 'priority', 'due_date', 'status', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => SubmissionType::class,
            'priority' => SubmissionPriority::class,
            'status' => SubmissionStatus::class,
            'due_date' => 'date',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $submission) {
            if ($submission->reference_number === null) {
                $submission->reference_number = app(ReferenceNumberGenerator::class)->next();
            }
        });
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function files()
    {
        return $this->hasMany(SubmissionFile::class);
    }

    public function workflowInstance()
    {
        return $this->hasOne(WorkflowInstance::class);
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
