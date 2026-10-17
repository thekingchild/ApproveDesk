<?php

namespace App\Models;

use Database\Factories\SensitiveFieldAccessFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $submission_id
 * @property int $request_field_definition_id
 * @property string $context
 * @property string|null $ip_address
 * @property Carbon|null $created_at
 */
class SensitiveFieldAccess extends Model
{
    /** @use HasFactory<SensitiveFieldAccessFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'submission_id',
        'request_field_definition_id',
        'context',
        'ip_address',
        'created_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return BelongsTo<RequestFieldDefinition, $this>
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(RequestFieldDefinition::class, 'request_field_definition_id');
    }
}
