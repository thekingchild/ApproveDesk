<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Branding;
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
        Storage::fake('public');
    }

    public function test_login_page_shows_default_branding(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Approvals, clearly tracked.')
            ->assertSee('Sign up')
            ->assertSee('<title>', false)
            ->assertSee('Log in - '.config('app.name'), false);
    }

    public function test_login_page_reflects_custom_branding_from_settings(): void
    {
        Setting::put('brand.tagline', 'Sign fast, sleep well.');
        Setting::put('brand.accent_color', '#ff5a5f');
        Setting::put('brand.meta_title', 'Northwind Approvals');
        Setting::put('brand.meta_description', 'Approve requests for Northwind.');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Sign fast, sleep well.')
            ->assertSee('#ff5a5f')
            ->assertSee('Northwind Approvals')
            ->assertSee('Approve requests for Northwind.')
            ->assertSee('name="description"', false);
    }

    public function test_login_page_uses_uploaded_background_image(): void
    {
        Storage::disk('public')->put('branding/bg.jpg', UploadedFile::fake()->image('bg.jpg')->getContent());
        Setting::put('brand.login_background', 'branding/bg.jpg');

        $this->get('/login')
            ->assertOk()
            ->assertSee('/branding/assets/bg.jpg', false)
            ->assertSee('data-test="login-background"', false)
            ->assertSee('data-test="login-background-mobile"', false)
            ->assertDontSee('/storage/branding/bg.jpg', false);
    }

    public function test_background_asset_is_served_without_a_storage_symlink(): void
    {
        Storage::disk('public')->put('branding/bg.jpg', UploadedFile::fake()->image('bg.jpg', 40, 30)->getContent());

        $this->get(route('branding.asset', 'bg.jpg'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_branding_asset_route_rejects_unknown_and_unsafe_names(): void
    {
        $this->get('/branding/assets/missing.jpg')->assertNotFound();
        $this->get('/branding/assets/../.env')->assertNotFound();
        $this->get('/branding/assets/notes.txt')->assertNotFound();
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
            ->assertSee('Branding')
            ->assertSee('Application name')
            ->assertSee('Favicon')
            ->assertSee('Meta description');
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
            ->set('meta_title', 'Desk of Approvals')
            ->set('meta_description', 'Internal request desk.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Approve everything.', Setting::get('brand.tagline'));
        $this->assertSame('#00aa55', Setting::get('brand.accent_color'));
        $this->assertSame('55', Setting::get('brand.login_background_overlay'));
        $this->assertSame('false', Setting::get('auth.allow_registration'));
        $this->assertSame('Desk of Approvals', Setting::get('brand.meta_title'));
        $this->assertSame('Internal request desk.', Setting::get('brand.meta_description'));

        auth()->forgetGuards();

        $this->get('/login')
            ->assertSee('Approve everything.')
            ->assertDontSee('Sign up')
            ->assertSee('Desk of Approvals')
            ->assertSee('Internal request desk.');
    }

    public function test_background_upload_stores_file_and_updates_setting(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.branding')
            ->set('background', UploadedFile::fake()->image('bg.jpg', 1200, 800))
            ->call('save')
            ->assertHasNoErrors();

        $path = Setting::get('brand.login_background');
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);

        $file = basename((string) $path);

        auth()->forgetGuards();

        $this->get('/login')
            ->assertOk()
            ->assertSee('/branding/assets/'.$file, false);

        $this->get(route('branding.asset', $file))->assertOk();
    }

    public function test_logo_and_favicon_upload_appear_on_login_and_in_the_app(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.branding')
            ->set('logo', UploadedFile::fake()->image('logo.png', 128, 128))
            ->set('favicon', UploadedFile::fake()->image('icon.png', 32, 32))
            ->set('meta_title', 'Acme Desk')
            ->call('save')
            ->assertHasNoErrors();

        $logo = basename((string) Setting::get('brand.logo'));
        $favicon = basename((string) Setting::get('brand.favicon'));

        $this->assertNotSame('', $logo);
        $this->assertNotSame('', $favicon);

        auth()->forgetGuards();

        $this->get('/login')
            ->assertOk()
            ->assertSee('/branding/assets/'.$logo, false)
            ->assertSee('/branding/assets/'.$favicon, false)
            ->assertSee('Acme Desk');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('/branding/assets/'.$logo, false)
            ->assertSee('/branding/assets/'.$favicon, false)
            ->assertSee('Acme Desk');
    }

    public function test_removing_logo_and_favicon_restores_defaults(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Storage::disk('public')->put('branding/logo.png', 'logo');
        Storage::disk('public')->put('branding/icon.png', 'icon');
        Setting::put('brand.logo', 'branding/logo.png');
        Setting::put('brand.favicon', 'branding/icon.png');

        Livewire::actingAs($admin)
            ->test('pages::settings.branding')
            ->set('remove_logo', true)
            ->set('remove_favicon', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Setting::get('brand.logo'));
        $this->assertNull(Setting::get('brand.favicon'));
        Storage::disk('public')->assertMissing('branding/logo.png');
        Storage::disk('public')->assertMissing('branding/icon.png');
    }

    public function test_branding_helper_falls_back_when_the_stored_file_is_missing(): void
    {
        Setting::put('brand.login_background', 'branding/gone.jpg');

        $this->assertNull(Branding::backgroundUrl());
        $this->assertSame(config('app.name'), Branding::name());
    }
}
