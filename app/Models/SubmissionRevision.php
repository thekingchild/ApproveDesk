<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property int $submission_id
 * @property int|null $actor_id
 * @property string $summary
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property Carbon|null $created_at
 */
class SubmissionRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['submission_id', 'actor_id', 'summary', 'before', 'after'];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('submission_revisions is append-only; updates are forbidden.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('submission_revisions is append-only; deletes are forbidden.');
        });
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
