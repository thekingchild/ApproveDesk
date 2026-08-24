<?php

namespace App\Models;

use App\Enums\DispositionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $submission_id
 * @property DispositionStatus $status
 * @property int $requested_by
 * @property int|null $reviewed_by
 * @property string|null $notes
 * @property Carbon|null $reviewed_at
 */
class DispositionReview extends Model
{
    protected $fillable = [
        'submission_id', 'status', 'requested_by', 'reviewed_by', 'notes', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DispositionStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }
}
