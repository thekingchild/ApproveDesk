<?php

namespace App\Models;

use Database\Factories\LoginEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string $fingerprint
 * @property bool $was_new_device
 * @property bool $notified
 * @property Carbon|null $created_at
 */
class LoginEvent extends Model
{
    /** @use HasFactory<LoginEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'fingerprint',
        'was_new_device',
        'notified',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'was_new_device' => 'boolean',
            'notified' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function fingerprint(?string $ip, ?string $userAgent): string
    {
        return hash('sha256', trim((string) $ip).'|'.trim((string) $userAgent));
    }
}
