<?php

namespace App\Actions\Announcements;

use App\Enums\AnnouncementDisplayMode;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\User;
use App\Services\Announcements\AnnouncementBannerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcknowledgeAnnouncementAction
{
    public function __construct(private readonly AnnouncementBannerService $banners) {}

    public function execute(Announcement $announcement, User $user): AnnouncementReceipt
    {
        return DB::transaction(function () use ($announcement, $user): AnnouncementReceipt {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            if ($locked->display_mode !== AnnouncementDisplayMode::Acknowledge) {
                throw ValidationException::withMessages(['announcement' => 'This announcement cannot be acknowledged.']);
            }
            if (! $this->banners->isCurrentlyTargeted($locked, $user)) {
                throw ValidationException::withMessages(['announcement' => 'This announcement is not currently available to you.']);
            }

            $receipt = $this->banners->recordDisplayed($locked, $user);
            if ($receipt->acknowledged_at === null) {
                $receipt->forceFill(['acknowledged_at' => now()])->save();
            }

            return $receipt->fresh();
        });
    }
}
