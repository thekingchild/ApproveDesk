<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property string $public_key
 * @property string $auth_token
 * @property string $content_encoding
 */
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    /** Browser push keys never appear in arrays or JSON (RV-LAR-05). */
    protected $hidden = ['auth_token', 'public_key'];

    protected $fillable = ['user_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding'];

    protected static function booted(): void
    {
        static::saving(function (self $subscription): void {
            $subscription->endpoint_hash = hash('sha256', (string) $subscription->endpoint);
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
