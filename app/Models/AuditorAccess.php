<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $granted_by
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by
 * @property string|null $reason
 * @property array<int, string>|null $scopes
 */
class AuditorAccess extends Model
{
    protected $fillable = [
        'user_id', 'granted_by', 'starts_at', 'ends_at', 'revoked_at', 'revoked_by', 'reason', 'scopes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'revoked_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    /** @param Builder<AuditorAccess> $query */
    public function scopeActive(Builder $query, ?CarbonInterface $at = null): void
    {
        $at ??= now();

        $query->whereNull('revoked_at')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    public function isActive(?CarbonInterface $at = null): bool
    {
        $at ??= now();

        return $this->revoked_at === null
            && $this->starts_at->lessThanOrEqualTo($at)
            && $this->ends_at->greaterThan($at);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function grantor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
