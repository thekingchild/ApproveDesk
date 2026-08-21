<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $kind
 * @property string $subject
 * @property string $body
 * @property bool $is_custom
 * @property int|null $updated_by
 */
class NotificationEmailTemplate extends Model
{
    protected $fillable = ['kind', 'subject', 'body', 'is_custom', 'updated_by'];

    protected function casts(): array
    {
        return [
            'is_custom' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
