<?php

namespace App\Actions\Announcements;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class ArchiveAnnouncementAction
{
    public function execute(Announcement $announcement, User $actor): Announcement
    {
        Gate::forUser($actor)->authorize('archive', $announcement);
        $announcement->update(['status' => AnnouncementStatus::Archived, 'archived_at' => now()]);

        return $announcement->fresh();
    }
}
