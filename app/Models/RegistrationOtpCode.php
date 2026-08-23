<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Hashed, single-use registration confirmation codes (Phase UO.2).
 * The plaintext code is never stored; attempts and expiry are enforced here.
 */
class RegistrationOtpCode extends Model
{
    protected $fillable = ['email', 'code_hash', 'expires_at', 'attempts', 'consumed_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
