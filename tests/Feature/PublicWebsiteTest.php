<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_guest_home_redirects_to_login_when_landing_page_is_off_by_default(): void
    {
        $this->get(route('home'))
            ->assertRedirect(route('login'));
    }

    public function test_guest_home_serves_the_static_site_when_landing_page_is_enabled(): void
    {
        Setting::put('brand.landing_page_enabled', 'true');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-approvedesk-auth', false)
            ->assertSee('Stop chasing approvals in chat threads')
            ->assertSee('Log in')
            ->assertSee('Sign up')
            ->assertDontSee("Let's get started");
    }

    public function test_authenticated_home_redirects_to_dashboard_when_landing_page_is_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_authenticated_home_shows_dashboard_link_when_landing_page_is_enabled(): void
    {
        Setting::put('brand.landing_page_enabled', 'true');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertDontSee('Log in')
            ->assertDontSee('Sign up');
    }

    public function test_signup_is_hidden_when_registration_is_closed(): void
    {
        Setting::put('brand.landing_page_enabled', 'true');
        Setting::put('auth.allow_registration', 'false');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Log in')
            ->assertDontSee('Sign up');
    }

    public function test_website_stylesheet_is_not_served_when_landing_page_is_off(): void
    {
        $this->get('/css/app.css')->assertNotFound();
    }

    public function test_website_stylesheet_is_served_when_landing_page_is_enabled(): void
    {
        Setting::put('brand.landing_page_enabled', 'true');

        $this->get('/css/app.css')
            ->assertOk()
            ->assertHeaderContains('Content-Type', 'text/css');
    }

    public function test_website_html_fallback_follows_the_landing_page_option(): void
    {
        $this->get('/index.html')->assertNotFound();

        Setting::put('brand.landing_page_enabled', 'true');

        $this->get('/index.html')
            ->assertOk()
            ->assertSee('Stop chasing approvals in chat threads');
    }

    public function test_dashboard_is_still_the_application(): void
    {
        $user = User::factory()->create()->assignRole('admin');

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Stop chasing approvals in chat threads');
    }

    public function test_login_is_still_the_application(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('Log in to your account'));
    }

    public function test_application_and_livewire_scripts_bypass_cloudflare_rocket_loader(): void
    {
        $html = $this->get(route('login'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<script[^>]*data-cfasync="false"[^>]*src="[^"]*\/livewire[^"?]*\/livewire\.js[^>]*>/',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<script[^>]*data-cfasync="false"[^>]*src="[^"]*\/flux\/flux(?:\.min)?\.js[^>]*>/',
            $html,
        );
    }

    public function test_path_traversal_and_dotfiles_are_not_served(): void
    {
        Setting::put('brand.landing_page_enabled', 'true');

        $this->get('/../.env')->assertNotFound();
        $this->get('/.env')->assertNotFound();
        $this->get('/missing.css')->assertNotFound();
    }
}
