<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\OutOfOfficeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int|null $fallback_user_id
 * @property string|null $message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read User|null $fallback
 */
class OutOfOffice extends Model
{
    /** @use HasFactory<OutOfOfficeFactory> */
    use HasFactory;

    protected $table = 'user_out_of_office';

    protected $fillable = [
        'user_id', 'starts_at', 'ends_at', 'fallback_user_id', 'message',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @param Builder<OutOfOffice> $query */
    public function scopeActive(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();

        $query->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }

    /** @param Builder<OutOfOffice> $query */
    public function scopeOverlapping(Builder $query, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $query->where('starts_at', '<', $endsAt)->where('ends_at', '>', $startsAt);
    }

    public function covers(CarbonInterface $at): bool
    {
        return $this->starts_at->lessThanOrEqualTo($at) && $this->ends_at->greaterThan($at);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function fallback(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fallback_user_id');
    }
}
