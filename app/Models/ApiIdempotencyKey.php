<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiIdempotencyKey extends Model
{
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
}
