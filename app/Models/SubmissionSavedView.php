<?php

namespace App\Models;

use Database\Factories\SubmissionSavedViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionSavedView extends Model
{
    /** @use HasFactory<SubmissionSavedViewFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'request_type_id', 'name', 'filters', 'columns'];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'columns' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<RequestType, $this> */
    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class);
    }
}
