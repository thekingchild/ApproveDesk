<?php

namespace App\Livewire;

use App\Actions\Announcements\AcknowledgeAnnouncementAction;
use App\Enums\AnnouncementDisplayMode;
use App\Models\Announcement;
use App\Models\User;
use App\Services\Announcements\AnnouncementBannerService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AnnouncementBanner extends Component
{
    /** @var list<int> */
    public array $announcementIds = [];

    public function mount(AnnouncementBannerService $banners): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        $announcements = $banners->eligibleFor($user);
        $this->announcementIds = array_values($announcements
            ->map(fn (Announcement $announcement): int => $announcement->id)
            ->all());

        foreach ($announcements as $announcement) {
            if ($announcement->display_mode === AnnouncementDisplayMode::ShowOnce) {
                $banners->recordDisplayed($announcement, $user);
            }
        }
    }

    public function acknowledge(int $announcementId, AcknowledgeAnnouncementAction $action): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);
        $announcement = Announcement::query()->findOrFail($announcementId);
        $action->execute($announcement, $user);
        $this->announcementIds = array_values(array_diff($this->announcementIds, [$announcementId]));
    }

    /** @return Collection<int, Announcement> */
    public function announcements(): Collection
    {
        if ($this->announcementIds === []) {
            return new Collection;
        }

        return Announcement::query()
            ->whereKey($this->announcementIds)
            ->whereIn('id', $this->announcementIds)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.announcement-banner', [
            'announcements' => $this->announcements(),
        ]);
    }
}
