<?php

namespace App\Models;

use Database\Factories\CommentRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Previous body of a comment. Append-only; the live comment keeps the latest text.
 *
 * @property int $id
 * @property int $comment_id
 * @property int $editor_id
 * @property string $body
 * @property Carbon|null $created_at
 */
class CommentRevision extends Model
{
    /** @use HasFactory<CommentRevisionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['comment_id', 'editor_id', 'body'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('comment_revisions is append-only; updates are forbidden.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('comment_revisions is append-only; deletes are forbidden.');
        });
    }

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editor_id');
    }
}
