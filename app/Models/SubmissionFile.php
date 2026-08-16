<?php

namespace App\Models;

use Database\Factories\SubmissionFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
