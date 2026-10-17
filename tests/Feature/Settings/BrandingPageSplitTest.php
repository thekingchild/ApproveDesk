<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\SystemOwner;
use App\Models\User;
use App\Services\Auth\SecondFactorAssurance;
use App\Services\Users\SystemOwnerService;
use App\Support\Branding;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** ADR 0006 split of the Branding page, and the RV-ARC-10 fixes that came with it. */
class BrandingPageSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->owner = User::factory()->create()->assignRole('super-admin');
        $this->owner->passkeys()->create([
            'name' => 'Owner key',
            'credential_id' => 'cred-branding-split-1',
            'credential' => ['id' => 'cred-branding-split-1'],
        ]);
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $this->owner->id]);
        app(SystemOwnerService::class)->forget();
    }

    public function test_rv_arc_10_adding_a_site_for_custom_code_needs_the_step_up_but_removing_one_does_not(): void
    {
        Setting::put(Branding::CUSTOM_CODE_ORIGINS, "https://a.example.com\nhttps://b.example.com");
        $this->actingAs($this->owner);

        Livewire::actingAs($this->owner)->test('settings.branding.custom-code')
            ->set('custom_code_origins', "https://a.example.com\nhttps://b.example.com\nhttps://new.example.com")
            ->call('save')
            ->assertRedirect(route('password.confirm'));
        $this->assertSame(['https://a.example.com', 'https://b.example.com'], Branding::customCodeOrigins());
        $this->assertSame("https://a.example.com\nhttps://b.example.com\nhttps://new.example.com", session('branding.pending_custom_code_origins'));

        session()->forget(['branding.pending_custom_code', 'branding.pending_custom_code_origins']);
        Livewire::actingAs($this->owner)->test('settings.branding.custom-code')
            ->set('custom_code_origins', 'https://a.example.com')
            ->call('save')
            ->assertHasNoErrors()
            ->assertNoRedirect();
        $this->assertSame(['https://a.example.com'], Branding::customCodeOrigins());
    }

    public function test_rv_arc_10_a_fresh_password_without_a_second_factor_in_this_session_is_refused(): void
    {
        $this->actingAs($this->owner);
        session(['auth.password_confirmed_at' => time()]);

        Livewire::actingAs($this->owner)->test('settings.branding.custom-code')
            ->set('custom_code', 'window.TRACK = 1;')
            ->call('save')
            ->assertHasErrors(['custom_code']);
        $this->assertTrue(blank(Setting::get('brand.custom_code')));

        session([SecondFactorAssurance::SESSION_KEY => ['user_id' => (string) $this->owner->id, 'verified_at' => time(), 'method' => 'passkey']]);
        Livewire::actingAs($this->owner)->test('settings.branding.custom-code')
            ->set('custom_code', 'window.TRACK = 1;')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('window.TRACK = 1;', Setting::get('brand.custom_code'));
    }

    public function test_the_snippet_kept_for_the_password_confirmation_comes_back_after_it(): void
    {
        $this->actingAs($this->owner);
        session(['branding.pending_custom_code' => 'window.KEPT = 1;']);

        Livewire::actingAs($this->owner)->test('settings.branding.custom-code')
            ->assertSet('custom_code', 'window.KEPT = 1;');
    }

    public function test_origin_entries_must_be_https_origins(): void
    {
        Livewire::actingAs($this->owner)->test('settings.branding.custom-code')
            ->set('custom_code_origins', 'http://insecure.example.com')
            ->call('save')
            ->assertHasErrors(['custom_code_origins']);
    }

    public function test_choosing_a_new_logo_offers_to_keep_the_current_one(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        // The preview used to overwrite $logo, so this link never showed.
        Livewire::actingAs($admin)->test('pages::settings.branding')
            ->set('logo', UploadedFile::fake()->image('logo.png', 64, 64))
            ->assertSee('Keep current logo instead')
            ->call('keepCurrent', 'logo')
            ->assertDontSee('Keep current logo instead');
    }

    public function test_an_administrator_sees_public_access_but_not_custom_code(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)->get(route('settings.branding'))
            ->assertOk()
            ->assertSee('data-test="public-access-card"', false)
            ->assertDontSee('data-test="custom-code-card"', false);
    }
}
