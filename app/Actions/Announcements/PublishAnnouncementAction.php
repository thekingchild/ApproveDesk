<?php

namespace App\Actions\Announcements;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class PublishAnnouncementAction
{
    public function execute(Announcement $announcement, User $actor): Announcement
    {
        Gate::forUser($actor)->authorize('publish', $announcement);

        return DB::transaction(function () use ($announcement): Announcement {
            $locked = Announcement::query()->lockForUpdate()->findOrFail($announcement->id);
            $locked->receipts()->delete();
            $locked->update(['status' => AnnouncementStatus::Published, 'published_at' => now(), 'archived_at' => null]);

            return $locked->fresh();
        });
    }
}
