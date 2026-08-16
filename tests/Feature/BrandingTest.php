<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_page_shows_default_branding(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Approvals, clearly tracked.')
            ->assertSee('Sign up');
    }

    public function test_login_page_reflects_custom_branding_from_settings(): void
    {
        Setting::put('brand.tagline', 'Sign fast, sleep well.');
        Setting::put('brand.accent_color', '#ff5a5f');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign fast, sleep well.')
            ->assertSee('#ff5a5f');
    }

    public function test_login_page_uses_uploaded_background_image(): void
    {
        Storage::fake('public');
        Setting::put('brand.login_background', 'branding/bg.jpg');

        $this->get('/login')
            ->assertOk()
            ->assertSee(Storage::disk('public')->url('branding/bg.jpg'));
    }

    public function test_custom_css_is_injected_and_sanitized(): void
    {
        Setting::put('brand.custom_css', 'body { margin: 0; } </style><script>alert(1)</script>');

        $response = $this->get('/login')->assertOk();

        $this->assertStringContainsString('body { margin: 0; }', $response->getContent());
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
    }

    public function test_registration_can_be_closed(): void
    {
        Setting::put('auth.allow_registration', 'false');

        $this->get('/login')->assertOk()->assertDontSee('Sign up');
        $this->get('/register')->assertNotFound();
    }

    public function test_branding_settings_page_requires_permission(): void
    {
        $this->get(route('settings.branding'))->assertRedirect('/login');

        $this->actingAs(User::factory()->create()->assignRole('viewer'))
            ->get(route('settings.branding'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('settings.branding'))
            ->assertOk()
            ->assertSee('Branding');
    }

    public function test_branding_can_be_saved_through_the_settings_page(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.branding')
            ->set('tagline', 'Approve everything.')
            ->set('accent_color', '#00aa55')
            ->set('overlay', 55)
            ->set('custom_css', '.brand-mark { border-radius: 0; }')
            ->set('allow_registration', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Approve everything.', Setting::get('brand.tagline'));
        $this->assertSame('#00aa55', Setting::get('brand.accent_color'));
        $this->assertSame('55', Setting::get('brand.login_background_overlay'));
        $this->assertSame('false', Setting::get('auth.allow_registration'));

        // Drop the authenticated admin so /login renders for a guest.
        auth()->forgetGuards();

        $this->get('/login')->assertSee('Approve everything.')->assertDontSee('Sign up');
    }

    public function test_background_upload_stores_file_and_updates_setting(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.branding')
            ->set('background', UploadedFile::fake()->image('bg.jpg', 1200, 800))
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull(Setting::get('brand.login_background'));
        Storage::disk('public')->assertExists(Setting::get('brand.login_background'));
    }
}
