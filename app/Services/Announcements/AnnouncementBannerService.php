<?php

namespace App\Services\Announcements;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

final class AnnouncementBannerService
{
    /** @return Collection<int, Announcement> */
    public function eligibleFor(User $user): Collection
    {
        if (! $user->is_active) {
            return new Collection;
        }
        try {
            $announcements = Announcement::query()
                ->where('status', AnnouncementStatus::Published->value)
                ->whereNotNull('published_at')->whereNull('archived_at')
                ->with(['receipts' => fn ($query) => $query->where('user_id', $user->id)])
                ->orderByDesc('published_at')->orderByDesc('id')->get();
        } catch (QueryException $exception) {
            if (! Schema::hasTable('announcements')) {
                return new Collection;
            }

            throw $exception;
        }

        $roleIds = $announcements->contains(
            fn (Announcement $announcement): bool => $announcement->audience === AnnouncementAudience::Roles,
        )
            ? array_values($user->roles()->pluck('roles.id')->map(fn ($id): int => (int) $id)->all())
            : [];

        return $announcements
            ->filter(function (Announcement $announcement) use ($roleIds): bool {
                if (! $this->matchesAudience($announcement, $roleIds)) {
                    return false;
                }
                $receipt = $announcement->receipts->first();

                return match ($announcement->display_mode) {
                    AnnouncementDisplayMode::Sticky => true,
                    AnnouncementDisplayMode::Acknowledge => $receipt?->acknowledged_at === null,
                    AnnouncementDisplayMode::ShowOnce => $receipt?->first_displayed_at === null,
                };
            })->values();
    }

    public function recordDisplayed(Announcement $announcement, User $user): AnnouncementReceipt
    {
        $now = now();

        AnnouncementReceipt::query()->insertOrIgnore([
            'announcement_id' => $announcement->id,
            'user_id' => $user->id,
            'first_displayed_at' => $now,
            'acknowledged_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        AnnouncementReceipt::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->whereNull('first_displayed_at')
            ->update(['first_displayed_at' => $now, 'updated_at' => $now]);

        return AnnouncementReceipt::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    public function isCurrentlyTargeted(Announcement $announcement, User $user): bool
    {
        if (! $user->is_active
            || $announcement->status !== AnnouncementStatus::Published
            || $announcement->published_at === null
            || $announcement->archived_at !== null) {
            return false;
        }

        $roleIds = array_values($user->roles()->pluck('roles.id')->map(fn ($id): int => (int) $id)->all());

        return $this->matchesAudience($announcement, $roleIds);
    }

    /**
     * The announcements among $ids that are still published and aimed at $user, newest first.
     * Display-mode state is ignored so a show-once banner stays up for the page it was shown on.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Announcement>
     */
    public function stillTargetedAmong(User $user, array $ids): Collection
    {
        if ($ids === [] || ! $user->is_active) {
            return new Collection;
        }

        $announcements = Announcement::query()
            ->whereKey($ids)
            ->where('status', AnnouncementStatus::Published->value)
            ->whereNotNull('published_at')->whereNull('archived_at')
            ->orderByDesc('published_at')->orderByDesc('id')->get();

        $roleIds = $announcements->contains(
            fn (Announcement $announcement): bool => $announcement->audience === AnnouncementAudience::Roles,
        )
            ? array_values($user->roles()->pluck('roles.id')->map(fn ($id): int => (int) $id)->all())
            : [];

        return $announcements
            ->filter(fn (Announcement $announcement): bool => $this->matchesAudience($announcement, $roleIds))
            ->values();
    }

    /** @param list<int> $roleIds */
    private function matchesAudience(Announcement $announcement, array $roleIds): bool
    {
        return match ($announcement->audience) {
            AnnouncementAudience::All => true,
            AnnouncementAudience::Roles => ! empty($announcement->role_ids)
                && ! empty(array_intersect(array_map('intval', $announcement->role_ids), $roleIds)),
        };
    }
}
