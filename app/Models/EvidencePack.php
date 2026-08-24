<?php

namespace App\Models;

use Database\Factories\EvidencePackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $submission_id
 * @property int $generated_by
 * @property string $stored_path
 * @property string $disk
 * @property string $manifest_hash
 * @property string $signature
 * @property Carbon|null $created_at
 */
class EvidencePack extends Model
{
    /** @use HasFactory<EvidencePackFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'submission_id', 'generated_by', 'stored_path', 'disk', 'manifest_hash', 'signature',
    ];

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<User, $this> */
    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
