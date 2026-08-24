<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $submission_id
 * @property int $placed_by
 * @property string $reason
 * @property Carbon $placed_at
 * @property Carbon|null $released_at
 * @property int|null $released_by
 */
class LegalHold extends Model
{
    protected $fillable = [
        'submission_id', 'placed_by', 'reason', 'placed_at', 'released_at', 'released_by',
    ];

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /** @param Builder<LegalHold> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('released_at');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function placer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }
}
