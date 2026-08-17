<?php

namespace App\Models;

use Database\Factories\SubmissionFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $submission_id
 * @property string $original_name
 * @property string $stored_path
 * @property string $disk
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $uploaded_by
 * @property bool $is_redacted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SubmissionFile extends Model
{
    private const PREVIEWABLE_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /** @use HasFactory<SubmissionFileFactory> */
    use HasFactory;

    protected $fillable = [
        'submission_id', 'original_name', 'stored_path', 'disk',
        'mime_type', 'size_bytes', 'uploaded_by', 'is_redacted',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'is_redacted' => 'boolean',
        ];
    }

    public function isPreviewable(): bool
    {
        return in_array($this->mime_type, self::PREVIEWABLE_MIME_TYPES, true);
    }

    /**
     * @return BelongsTo<Submission, $this>
     */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
