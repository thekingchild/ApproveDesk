<?php

namespace App\Models;

use App\Enums\SubmissionPriority;
use App\Enums\SubmissionStatus;
use App\Enums\SubmissionType;
use App\Services\ReferenceNumberGenerator;
use App\Services\RichTextSanitizer;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $reference_number
 * @property SubmissionType $type
 * @property string $title
 * @property string|null $body
 * @property int $submitter_id
 * @property int|null $department_id
 * @property SubmissionPriority $priority
 * @property Carbon|null $due_date
 * @property SubmissionStatus $status
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $submitter
 * @property-read Department|null $department
 * @property-read Collection<int, SubmissionFile> $files
 * @property-read WorkflowInstance|null $workflowInstance
 * @property-read Collection<int, Comment> $comments
 * @property-read Collection<int, Tag> $tags
 */
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
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

    /** @return Attribute<string|null, string|null> */
    protected function body(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null
                ? null
                : app(RichTextSanitizer::class)->sanitize($value),
        );
    }

    protected static function booted(): void
    {
        static::creating(function (self $submission) {
            if ($submission->reference_number === null) {
                $submission->reference_number = app(ReferenceNumberGenerator::class)->next();
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitter_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * @return HasMany<SubmissionFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class);
    }

    /**
     * @return HasOne<WorkflowInstance, $this>
     */
    public function workflowInstance(): HasOne
    {
        return $this->hasOne(WorkflowInstance::class);
    }

    /**
     * @return MorphMany<Comment, $this>
     */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
