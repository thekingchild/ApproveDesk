<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AnnouncementReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $announcement_id
 * @property int $user_id
 * @property CarbonImmutable|null $first_displayed_at
 * @property CarbonImmutable|null $acknowledged_at
 */
class AnnouncementReceipt extends Model
{
    /** @use HasFactory<AnnouncementReceiptFactory> */
    use HasFactory;

    protected $fillable = ['announcement_id', 'user_id', 'first_displayed_at', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['first_displayed_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Announcement, $this> */
    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
