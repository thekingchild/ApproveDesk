<?php

namespace App\Models;

use App\Enums\OAuthProvider;
use Database\Factories\OAuthIdentityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property OAuthProvider $provider
 * @property string $provider_user_id
 * @property string $email
 * @property string|null $avatar
 * @property-read User $user
 */
class OAuthIdentity extends Model
{
    /** @use HasFactory<OAuthIdentityFactory> */
    use HasFactory;

    protected $table = 'oauth_identities';

    protected $fillable = ['user_id', 'provider', 'provider_user_id', 'email', 'avatar'];

    protected function casts(): array
    {
        return ['provider' => OAuthProvider::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
