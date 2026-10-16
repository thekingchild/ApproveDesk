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
 * @property int $request_type_version_id
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
 * @property-read RequestTypeVersion $requestTypeVersion
 * @property-read Collection<int, SubmissionFieldValue> $fieldValues
 * @property-read Collection<int, SubmissionFile> $files
 * @property-read WorkflowInstance|null $workflowInstance
 * @property-read Collection<int, Comment> $comments
 * @property-read Collection<int, Tag> $tags
 * @property-read Collection<int, SubmissionWatcher> $watchers
 * @property-read Collection<int, SubmissionNudge> $nudges
 */
class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference_number', 'type', 'request_type_version_id', 'title', 'body', 'submitter_id',
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

        static::updated(function (self $submission): void {
            $tracked = ['title', 'body', 'status', 'priority', 'due_date'];
            $before = [];
            $after = [];

            foreach ($tracked as $field) {
                if (! $submission->wasChanged($field)) {
                    continue;
                }

                $original = $submission->getOriginal($field);
                $current = $submission->getAttribute($field);
                $before[$field] = $original instanceof \BackedEnum ? $original->value : (string) ($original ?? '');
                $after[$field] = $current instanceof \BackedEnum ? $current->value : (string) ($current ?? '');
            }

            if ($before === []) {
                return;
            }

            SubmissionRevision::query()->create([
                'submission_id' => $submission->id,
                'actor_id' => auth()->id(),
                'summary' => implode(', ', array_map(fn (string $field): string => str_replace('_', ' ', $field), array_keys($after))),
                'before' => $before,
                'after' => $after,
            ]);
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

    /** @return BelongsTo<RequestTypeVersion, $this> */
    public function requestTypeVersion(): BelongsTo
    {
        return $this->belongsTo(RequestTypeVersion::class);
    }

    /** @return HasMany<SubmissionFieldValue, $this> */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(SubmissionFieldValue::class);
    }

    /**
     * @return HasMany<SubmissionFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class);
    }

    /**
     * Attachments the requester removed after submitting; kept as evidence (RV-DATA-06).
     *
     * @return HasMany<SubmissionFile, $this>
     */
    public function removedFiles(): HasMany
    {
        return $this->hasMany(SubmissionFile::class)->onlyTrashed()->latest('deleted_at');
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

    /** @return HasMany<SubmissionWatcher, $this> */
    public function watchers(): HasMany
    {
        return $this->hasMany(SubmissionWatcher::class);
    }

    /** @return HasMany<SubmissionNudge, $this> */
    public function nudges(): HasMany
    {
        return $this->hasMany(SubmissionNudge::class);
    }

    /** @return HasMany<LegalHold, $this> */
    public function legalHolds(): HasMany
    {
        return $this->hasMany(LegalHold::class);
    }

    /** @return HasMany<DispositionReview, $this> */
    public function dispositionReviews(): HasMany
    {
        return $this->hasMany(DispositionReview::class);
    }

    /** @return HasMany<SubmissionRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(SubmissionRevision::class)->orderByDesc('id');
    }

    /** @return HasMany<SubmissionFileRevision, $this> */
    public function fileRevisions(): HasMany
    {
        return $this->hasMany(SubmissionFileRevision::class)->orderByDesc('id');
    }

    public function isOnLegalHold(): bool
    {
        return $this->legalHolds()->active()->exists();
    }

    /**
     * Followers invited by someone else (requester, approver, mention) may view
     * the request. Following yourself never grants access on its own, so a
     * time-limited auditor grant or delegation cannot be made permanent (RV-SEC-08).
     */
    public function grantsFollowerAccessTo(User $user): bool
    {
        if ($this->relationLoaded('watchers')) {
            return $this->watchers->contains(
                fn (SubmissionWatcher $watcher): bool => $watcher->user_id === $user->id
                    && ($watcher->added_by_id === null || $watcher->added_by_id !== $user->id),
            );
        }

        return $this->watchers()
            ->where('user_id', $user->id)
            ->where(fn ($query) => $query->whereNull('added_by_id')->orWhere('added_by_id', '!=', $user->id))
            ->exists();
    }

    public function isWatchedBy(User $user): bool
    {
        if ($this->relationLoaded('watchers')) {
            return $this->watchers->contains('user_id', $user->id);
        }

        return $this->watchers()->where('user_id', $user->id)->exists();
    }
}
