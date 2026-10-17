<?php

namespace App\Models;

use Database\Factories\RegistrationOtpCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Hashed, single-use registration confirmation codes (Phase UO.2).
 * The plaintext code is never stored; attempts and expiry are enforced here.
 *
 * @property Carbon $expires_at
 */
class RegistrationOtpCode extends Model
{
    /** @use HasFactory<RegistrationOtpCodeFactory> */
    use HasFactory;

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
