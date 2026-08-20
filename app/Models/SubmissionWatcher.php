<?php

namespace App\Models;

use Database\Factories\SubmissionWatcherFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Explicit follower of a request. Presence grants view access.
 *
 * @property int $id
 * @property int $submission_id
 * @property int $user_id
 * @property int|null $added_by_id
 * @property bool $notify_comments
 * @property bool $notify_status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Submission $submission
 * @property-read User $user
 * @property-read User|null $addedBy
 */
class SubmissionWatcher extends Model
{
    /** @use HasFactory<SubmissionWatcherFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id', 'user_id', 'added_by_id', 'notify_comments', 'notify_status',
    ];

    protected function casts(): array
    {
        return [
            'notify_comments' => 'boolean',
            'notify_status' => 'boolean',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
