<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property int $id
 * @property int $submission_id
 * @property int|null $submission_file_id
 * @property int|null $actor_id
 * @property string $action
 * @property string $original_name
 * @property string|null $sha256
 * @property int|null $size_bytes
 * @property Carbon|null $created_at
 */
class SubmissionFileRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'submission_id', 'submission_file_id', 'actor_id', 'action', 'original_name', 'sha256', 'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('submission_file_revisions is append-only; updates are forbidden.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('submission_file_revisions is append-only; deletes are forbidden.');
        });
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
