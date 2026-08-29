<?php

namespace Tests\Feature;

use App\Actions\Settings\UpdateBrandingSettingsAction;
use App\DTOs\BrandingSettingsData;
use App\Models\Setting;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Roles\RolePermissionService;
use App\Services\Users\SystemOwnerService;
use App\Support\Branding;
use App\Support\SecondFactorAssurance;
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
            ->assertDontSee('/storage/branding/bg.jpg', false)
            ->assertDontSee('bg-white/95', false)
            ->assertSee('lg:bg-white', false);
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

    public function test_login_has_no_custom_code_by_default(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertDontSee('approvedesk-custom-code', false);
    }

    public function test_custom_code_is_injected_on_login_and_in_the_app(): void
    {
        Setting::put('brand.custom_code', '<script src="https://www.example-analytics.test/tracker.js"></script>');

        $this->get('/login')
            ->assertOk()
            ->assertSee('approvedesk-custom-code', false)
            ->assertSee('https://www.example-analytics.test/tracker.js', false);

        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('https://www.example-analytics.test/tracker.js', false);
    }

    public function test_plain_javascript_custom_code_is_wrapped_in_a_script_tag(): void
    {
        Setting::put('brand.custom_code', 'window.APPROVEDESK_MARK = 1;');

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('<script>window.APPROVEDESK_MARK = 1;</script>', $html);
    }

    public function test_javascript_protocol_sources_are_stripped_from_custom_code(): void
    {
        Setting::put('brand.custom_code', '<script src="javascript:alert(1)"></script><script src="https://plausible.test/js/script.js"></script>');

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringNotContainsString('javascript:alert(1)', $html);
        $this->assertStringContainsString('https://plausible.test/js/script.js', $html);
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

    public function test_branding_page_groups_identity_and_login_sections_with_live_preview(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Storage::disk('public')->put('branding/logo.png', 'logo');
        Storage::disk('public')->put('branding/bg.jpg', 'bg');
        Setting::put('brand.logo', 'branding/logo.png');
        Setting::put('brand.login_background', 'branding/bg.jpg');

        $this->actingAs($admin)
            ->get(route('settings.branding'))
            ->assertOk()
            // Two clear sections with context subtitles.
            ->assertSee('Application identity')
            ->assertSee('Appears on login and throughout the app', false)
            ->assertSee('Show public landing page', false)
            ->assertSee('When off, the site address opens Login. Turn it on to show the public website.', false)
            ->assertSee('Login page')
            ->assertSee('What requesters see before they sign in', false)
            // Upload cards show the current assets inline.
            ->assertSee('/branding/assets/logo.png', false)
            ->assertSee('/branding/assets/bg.jpg', false)
            // Plain-language constraint copy next to each upload.
            ->assertSee('PNG, JPG or WebP · max 1 MB', false)
            ->assertSee('JPG, PNG or WebP · max 2 MB · shown at every screen width', false)
            // Advanced CSS is tucked away, preview has login + browser tabs, save is prominent.
            ->assertSee('Advanced: custom CSS', false)
            ->assertDontSee('Advanced: analytics and custom code', false)
            ->assertSee('Live preview', false)
            ->assertSee('preview-tab-browser', false)
            ->assertSee('data-test="save-branding"', false);
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
            ->set('custom_code', 'window.ADMIN_TRACK = 1;')
            ->set('allow_registration', false)
            ->set('meta_title', 'Desk of Approvals')
            ->set('meta_description', 'Internal request desk.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Approve everything.', Setting::get('brand.tagline'));
        $this->assertSame('#00aa55', Setting::get('brand.accent_color'));
        $this->assertSame('55', Setting::get('brand.login_background_overlay'));
        $this->assertSame('.brand-mark { border-radius: 0; }', Setting::get('brand.custom_css'));
        $this->assertTrue(blank(Setting::get('brand.custom_code')));
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

    public function test_branding_page_saves_the_landing_page_option(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.branding')
            ->set('landing_page_enabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('true', Setting::get('brand.landing_page_enabled'));

        auth()->forgetGuards();

        $this->get(route('home'))->assertOk();
        $this->get('/css/app.css')->assertOk();

        Livewire::actingAs($admin)
            ->test('pages::settings.branding')
            ->set('landing_page_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('false', Setting::get('brand.landing_page_enabled'));

        auth()->forgetGuards();

        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get('/css/app.css')->assertNotFound();
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

    public function test_action_writes_custom_code_only_for_authorized_actor(): void
    {
        $owner = $this->owner();
        $admin = User::factory()->create()->assignRole('admin');

        app(UpdateBrandingSettingsAction::class)->execute(new BrandingSettingsData(
            tagline: 'T',
            accentColor: '#4f46e5',
            overlay: 40,
            customCss: null,
            allowRegistration: true,
            customCode: '<script src="https://owner.test/tracker.js"></script>',
        ), $admin);
        $this->assertTrue(blank(Setting::get('brand.custom_code')));

        app(UpdateBrandingSettingsAction::class)->execute(new BrandingSettingsData(
            tagline: 'T',
            accentColor: '#4f46e5',
            overlay: 40,
            customCss: null,
            allowRegistration: true,
            customCode: '<script src="https://owner.test/tracker.js"></script>',
        ), $owner);
        $this->assertSame('<script src="https://owner.test/tracker.js"></script>', Setting::get('brand.custom_code'));

        auth()->forgetGuards();

        $this->get('/login')
            ->assertOk()
            ->assertSee('https://owner.test/tracker.js', false);

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('https://owner.test/tracker.js', false);
    }

    public function test_branding_manager_cannot_persist_or_clear_custom_code(): void
    {
        Setting::put('brand.custom_code', '<script src="https://kept.test/tracker.js"></script>');
        $manager = $this->brandingManager();

        Livewire::actingAs($manager)
            ->test('pages::settings.branding')
            ->assertDontSee('data-test="custom-code"', false)
            ->set('tagline', 'Brand desk.')
            ->set('custom_code', 'window.PWNED = 1;')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Brand desk.', Setting::get('brand.tagline'));
        $this->assertSame('<script src="https://kept.test/tracker.js"></script>', Setting::get('brand.custom_code'));
    }

    public function test_system_owner_needs_reauthentication_to_save_custom_code(): void
    {
        $owner = $this->owner();

        Livewire::actingAs($owner)
            ->test('pages::settings.branding')
            ->set('custom_code', '<script src="https://owner.test/tracker.js"></script>')
            ->call('save')
            ->assertForbidden();

        $this->assertTrue(blank(Setting::get('brand.custom_code')));
    }

    public function test_system_owner_sees_custom_code_editor(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        session($this->ownerSession($owner));
        $this->assertTrue($owner->can('branding.custom_code.manage'));

        $component = Livewire::actingAs($owner)
            ->test('pages::settings.branding')
            ->assertSee('data-test="custom-code"', false);

        $this->assertTrue($component->instance()->canManageCustomCode());
    }

    public function test_administrator_does_not_receive_custom_code_permission(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->assertFalse($admin->can('branding.custom_code.manage'));
        $this->assertTrue($admin->can('branding.manage'));
    }

    private function owner(): User
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        $owner->passkeys()->create([
            'name' => 'Owner key',
            'credential_id' => 'cred-branding-owner-1',
            'credential' => ['id' => 'cred-branding-owner-1'],
        ]);
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        app(SystemOwnerService::class)->forget();

        return $owner->fresh() ?? $owner;
    }

    private function brandingManager(): User
    {
        $role = app(RolePermissionService::class)->create($this->owner(), 'Brand editor', null, ['branding.manage']);

        return User::factory()->create()->assignRole($role->name);
    }

    /** @return array<string, mixed> */
    private function ownerSession(User $owner): array
    {
        return [
            'auth.password_confirmed_at' => time(),
            SecondFactorAssurance::SESSION_KEY => [
                'user_id' => (string) $owner->id,
                'verified_at' => time(),
                'method' => 'passkey',
            ],
        ];
    }
}
