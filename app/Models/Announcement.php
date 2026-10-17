<?php

namespace App\Models;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementStatus;
use App\Support\RichTextSanitizer;
use Carbon\CarbonImmutable;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $creator_id
 * @property string $title
 * @property string $message
 * @property AnnouncementAudience $audience
 * @property list<int>|null $role_ids
 * @property AnnouncementDisplayMode $display_mode
 * @property AnnouncementStatus $status
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $archived_at
 * @property-read User $creator
 * @property-read Collection<int, AnnouncementReceipt> $receipts
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    protected $fillable = ['creator_id', 'title', 'message', 'audience', 'role_ids', 'display_mode', 'status', 'published_at', 'archived_at'];

    protected function casts(): array
    {
        return [
            'audience' => AnnouncementAudience::class,
            'role_ids' => 'array',
            'display_mode' => AnnouncementDisplayMode::class,
            'status' => AnnouncementStatus::class,
            'published_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /**
     * Message HTML for display. Re-sanitized on render so content stored
     * before a sanitizer fix cannot reach the page (RV-SEC-03).
     */
    public function safeMessage(): string
    {
        return app(RichTextSanitizer::class)->sanitize((string) $this->message);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /** @return HasMany<AnnouncementReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(AnnouncementReceipt::class);
    }
}
