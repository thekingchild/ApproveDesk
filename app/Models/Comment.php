<?php

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Model $commentable
 * @property int $author_id
 * @property int|null $parent_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Comment extends Model
{
    public const EDIT_WINDOW_MINUTES = 15;

    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    protected $fillable = ['commentable_id', 'commentable_type', 'author_id', 'parent_id', 'body', 'edited_at'];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    /** @return HasMany<CommentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(CommentRevision::class)->orderBy('id');
    }

    /** @return HasMany<CommentMention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(CommentMention::class);
    }

    public function isWithinEditWindow(?Carbon $at = null): bool
    {
        $at ??= now();

        return $this->created_at !== null
            && $this->created_at->copy()->addMinutes(self::EDIT_WINDOW_MINUTES)->greaterThan($at);
    }

    public function isEditableBy(User $user, ?Carbon $at = null): bool
    {
        return $this->author_id === $user->id && $this->isWithinEditWindow($at);
    }
}
