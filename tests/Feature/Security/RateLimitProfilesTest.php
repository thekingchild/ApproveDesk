<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\User;
use App\Support\RateLimitProfiles;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class RateLimitProfilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::query()->delete();
        Artisan::call('cache:clear');
    }

    public function test_defaults_match_the_previous_hardcoded_limits(): void
    {
        $this->assertSame(5, RateLimitProfiles::perMinute('login'));
        $this->assertSame(60, RateLimitProfiles::perMinute('api'));
        $this->assertSame(10, RateLimitProfiles::perMinute('approval_links'));
        $this->assertSame(10, RateLimitProfiles::perMinute('exports'));
        $this->assertSame(60, RateLimitProfiles::perMinute('webhooks'));
        $this->assertSame(60, RateLimitProfiles::perMinute('integrations'));
    }

    public function test_administrator_can_change_profiles_and_login_uses_them(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.security-privacy')
            ->set('rate_limit_login', 1)
            ->set('rate_limit_api', 12)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, RateLimitProfiles::perMinute('login'));
        $this->assertSame(12, RateLimitProfiles::perMinute('api'));
        $this->assertSame(10, RateLimitProfiles::perMinute('exports'));
    }

    public function test_submitter_cannot_open_security_privacy_settings(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('settings.security-privacy'))
            ->assertForbidden();
    }
}
