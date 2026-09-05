<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $pending_user_id
 * @property string $proposed_name
 * @property string $normalized_name
 * @property string $status
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $reason
 * @property-read User $user
 * @property-read User|null $decisionActor
 */
class DisplayNameChange extends Model
{
    protected $fillable = ['user_id', 'pending_user_id', 'proposed_name', 'normalized_name', 'status', 'decided_by', 'decided_at', 'reason'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function decisionActor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
