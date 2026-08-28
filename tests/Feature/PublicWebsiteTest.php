<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_guest_home_serves_the_static_site_with_login_and_signup(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-approvedesk-auth', false)
            ->assertSee('Stop chasing approvals in chat threads')
            ->assertSee('Log in')
            ->assertSee('Sign up')
            ->assertDontSee("Let's get started");
    }

    public function test_authenticated_home_shows_dashboard_instead_of_login(): void
    {
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
        Setting::put('auth.allow_registration', 'false');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Log in')
            ->assertDontSee('Sign up');
    }

    public function test_website_stylesheet_is_served(): void
    {
        $this->get('/css/app.css')
            ->assertOk()
            ->assertHeaderContains('Content-Type', 'text/css');
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
        $this->withVite();

        $html = $this->get(route('login'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<script[^>]*data-cfasync="false"[^>]*src="[^"]*\/build\/assets\/app-[^"]*\.js"[^>]*>/',
            $html,
        );
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
        $this->get('/../.env')->assertNotFound();
        $this->get('/.env')->assertNotFound();
        $this->get('/missing.css')->assertNotFound();
    }
}
