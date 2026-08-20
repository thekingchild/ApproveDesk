<?php

namespace App\Models;

use Database\Factories\SubmissionNudgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Manual reminder to a current approver. Rows are not rewritten or deleted.
 *
 * @property int $id
 * @property int $submission_id
 * @property int $actor_id
 * @property int $recipient_id
 * @property int|null $step_id
 * @property string|null $message
 * @property Carbon $scheduled_for
 * @property bool $delayed_for_quiet_hours
 * @property Carbon|null $created_at
 */
class SubmissionNudge extends Model
{
    /** @use HasFactory<SubmissionNudgeFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'submission_id', 'actor_id', 'recipient_id', 'step_id', 'message',
        'scheduled_for', 'delayed_for_quiet_hours',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'delayed_for_quiet_hours' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('submission_nudges is append-only; updates are forbidden.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('submission_nudges is append-only; deletes are forbidden.');
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

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** @return BelongsTo<WorkflowTemplateStep, $this> */
    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplateStep::class, 'step_id');
    }
}
