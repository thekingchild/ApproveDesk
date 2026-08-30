<?php

namespace Tests\Feature;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementDisplayMode;
use App\Models\Announcement;
use App\Models\SystemOwner;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AnnouncementsAndThemesUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_owner_only_admin_routes_and_navigation(): void
    {
        $owner = $this->owner();
        $roleOnly = User::factory()->create()->assignRole('super-admin');

        $this->actingAs($owner)->get(route('admin.announcements.index'))->assertOk()->assertSee('Announcements');
        $this->actingAs($owner)->get(route('admin.themes.index'))->assertOk()->assertSee('Application themes');
        $this->actingAs($roleOnly)->get(route('admin.announcements.index'))->assertForbidden();
        $this->actingAs($roleOnly)->get(route('admin.themes.index'))->assertForbidden();
    }

    public function test_global_banner_is_targeted_and_show_once_records_on_render(): void
    {
        $owner = $this->owner();
        $target = User::factory()->create()->assignRole('approver');
        $other = User::factory()->create()->assignRole('submitter');
        $roleId = $target->roles()->firstOrFail()->id;
        Announcement::factory()->published()->create([
            'creator_id' => $owner->id, 'title' => 'Finance update',
            'audience' => AnnouncementAudience::Roles, 'role_ids' => [$roleId],
            'display_mode' => AnnouncementDisplayMode::ShowOnce,
        ]);

        $this->actingAs($other)->get(route('dashboard'))->assertDontSee('Finance update');
        $this->actingAs($target)->get(route('dashboard'))->assertOk()->assertSee('Finance update');
        $this->actingAs($target)->get(route('dashboard'))->assertDontSee('Finance update');
        $this->assertDatabaseCount('announcement_receipts', 1);
    }

    public function test_appearance_saves_theme_and_layout_emits_fixed_palette(): void
    {
        $user = User::factory()->create();
        $theme = Theme::query()->where('key', 'ocean')->firstOrFail();

        $this->actingAs($user)->get(route('appearance.edit'))->assertOk()->assertSee('Application theme')->assertSee('Ocean');
        $this->actingAs($user)->post(route('appearance.theme.update'), ['theme_id' => $theme->id])->assertRedirect(route('appearance.edit'));
        $this->assertSame($theme->id, $user->fresh()->theme_id);
        $this->actingAs($user)->get(route('dashboard'))->assertSee('--ad-primary: #0369A1', false)->assertSee('name="theme-color"', false);
    }

    public function test_owner_can_manage_announcements_through_the_livewire_page(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test('pages::admin.announcements')
            ->set('title', 'Planned maintenance')
            ->set('message', '<p>Services restart at 21:00.</p>')
            ->set('audience', 'all')
            ->set('displayMode', 'sticky')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Planned maintenance');

        $announcement = Announcement::query()->where('title', 'Planned maintenance')->firstOrFail();
        Livewire::actingAs($owner)
            ->test('pages::admin.announcements')
            ->call('publish', $announcement->id)
            ->assertHasNoErrors();
        $this->assertNotNull($announcement->fresh()->published_at);
    }

    public function test_owner_can_create_an_accessible_custom_theme_through_the_livewire_page(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test('pages::admin.application-themes')
            ->set('name', 'Forest')
            ->set('key', 'forest')
            ->set('primaryLight', '#166534')
            ->set('foregroundLight', '#FFFFFF')
            ->set('primaryDark', '#4ADE80')
            ->set('foregroundDark', '#052E16')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Forest');

        $this->assertDatabaseHas('themes', ['key' => 'forest', 'is_builtin' => false]);
    }

    private function owner(): User
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        return $owner;
    }
}
