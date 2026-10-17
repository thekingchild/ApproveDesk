<?php

namespace Tests\Feature\Announcements;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementDisplayMode;
use App\Enums\AnnouncementStatus;
use App\Livewire\AnnouncementBanner;
use App\Models\Announcement;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** RV-SEC-23: the banner must only render announcements the viewer is eligible for. */
class AnnouncementBannerTamperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_rv_sec_23_client_cannot_replace_the_announcement_ids(): void
    {
        $viewer = User::factory()->create();
        $draft = $this->announcement(['status' => AnnouncementStatus::Draft, 'published_at' => null, 'title' => 'Secret draft']);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::actingAs($viewer)->test(AnnouncementBanner::class)
            ->set('announcementIds', [$draft->id]);
    }

    public function test_rv_sec_23_banner_drops_announcements_no_longer_targeted_at_the_viewer(): void
    {
        $viewer = User::factory()->create();
        $live = $this->announcement(['title' => 'Visible notice']);

        $banner = Livewire::actingAs($viewer)->test(AnnouncementBanner::class)
            ->assertSee('Visible notice');

        $live->forceFill(['archived_at' => now()])->save();

        $banner->call('$refresh')->assertDontSee('Visible notice');
    }

    public function test_rv_sec_23_other_audience_announcements_are_not_rendered(): void
    {
        $viewer = User::factory()->create();
        $role = Role::findByName('admin');
        $this->announcement([
            'title' => 'Admins only',
            'audience' => AnnouncementAudience::Roles,
            'role_ids' => [$role->id],
        ]);

        Livewire::actingAs($viewer)->test(AnnouncementBanner::class)
            ->assertDontSee('Admins only');
    }

    /** @param array<string, mixed> $attributes */
    private function announcement(array $attributes): Announcement
    {
        return Announcement::factory()->create(array_merge([
            'creator_id' => User::factory()->create()->id,
            'audience' => AnnouncementAudience::All,
            'display_mode' => AnnouncementDisplayMode::Sticky,
            'status' => AnnouncementStatus::Published,
            'published_at' => now()->subMinute(),
            'archived_at' => null,
        ], $attributes));
    }
}
