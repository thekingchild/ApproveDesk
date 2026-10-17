<?php

namespace App\Models;

use Database\Factories\UserSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row in the framework sessions table.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $payload
 * @property int $last_activity
 */
class UserSession extends Model
{
    /** @use HasFactory<UserSessionFactory> */
    use HasFactory;

    protected $table = 'sessions';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function deviceLabel(): string
    {
        $agent = trim((string) $this->user_agent);

        if ($agent === '') {
            return 'Unknown device';
        }

        return match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Chrome/') && ! str_contains($agent, 'Edg/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') && ! str_contains($agent, 'Chrome/') => 'Safari',
            default => 'Browser',
        };
    }
}
