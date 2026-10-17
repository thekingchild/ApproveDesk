<?php

namespace App\Models;

use Database\Factories\ApiIdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $request_hash
 * @property int|null $status_code null while the claiming request is still running (RV-BUG-27)
 * @property array<array-key, mixed>|null $response_body
 */
class ApiIdempotencyKey extends Model
{
    /** @use HasFactory<ApiIdempotencyKeyFactory> */
    use HasFactory;

    use MassPrunable;

    /** Clients retry within minutes; a week keeps replays safe without growing forever. */
    public const KEEP_DAYS = 7;

    protected $fillable = [
        'user_id', 'key', 'method', 'path', 'request_hash', 'status_code', 'response_body',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'status_code' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
