<?php

namespace App\Models;

use Database\Factories\OpsBackupVerificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $verified_at
 * @property string $source
 * @property string|null $path
 * @property bool $ok
 * @property array<string, int> $table_counts
 * @property list<string> $issues
 * @property int|null $verified_by
 * @property-read User|null $verifier
 */
class OpsBackupVerification extends Model
{
    /** @use HasFactory<OpsBackupVerificationFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'verified_at',
        'source',
        'path',
        'ok',
        'table_counts',
        'issues',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'ok' => 'boolean',
            'table_counts' => 'array',
            'issues' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
