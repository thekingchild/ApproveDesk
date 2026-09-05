<?php

namespace Tests\Feature\Auth;

use App\Models\OAuthIdentity;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OAuthAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::query()->delete();
        Artisan::call('cache:clear');
        config([
            'services.oauth.google.client_id' => 'google-client',
            'services.oauth.google.client_secret' => 'google-secret',
            'services.oauth.google.redirect' => 'https://approvedesk.example.test/auth/google/callback',
            'services.oauth.microsoft.client_id' => 'microsoft-client',
            'services.oauth.microsoft.client_secret' => 'microsoft-secret',
            'services.oauth.microsoft.redirect' => 'https://approvedesk.example.test/auth/microsoft/callback',
            'services.oauth.microsoft.tenant' => 'organizations',
            'services.oauth.github.client_id' => 'github-client',
            'services.oauth.github.client_secret' => 'github-secret',
            'services.oauth.github.redirect' => 'https://approvedesk.example.test/auth/github/callback',
        ]);
    }

    public function test_google_redirect_uses_state_and_pkce(): void
    {
        $response = $this->get(route('oauth.redirect', 'google'));

        $response->assertRedirectContains('https://accounts.google.com/o/oauth2/v2/auth');
        $response->assertSessionHas('oauth.google');

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $attempt = session('oauth.google');

        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid profile email', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame($attempt['state'], $query['state']);
        $this->assertNotSame($attempt['verifier'], $query['code_challenge']);
    }

    public function test_configured_providers_are_shown_on_the_login_page(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Continue with Google')
            ->assertSee('Continue with Microsoft');
    }

    public function test_verified_google_identity_links_existing_user_and_logs_in(): void
    {
        $user = User::factory()->create(['email' => 'person@example.com']);
        $this->get(route('oauth.redirect', 'google'));
        $attempt = session('oauth.google');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-user-123',
                'name' => 'OAuth Person',
                'email' => 'Person@Example.com',
                'email_verified' => true,
                'picture' => 'https://images.example.test/avatar.png',
            ]),
        ]);

        $this->get(route('oauth.callback', ['provider' => 'google', 'state' => $attempt['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('oauth_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-user-123',
            'email' => 'person@example.com',
        ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['code_verifier'] === $attempt['verifier']);
    }

    public function test_microsoft_login_can_create_a_submitter_when_registration_is_open(): void
    {
        $this->get(route('oauth.redirect', 'microsoft'));
        $attempt = session('oauth.microsoft');

        Http::fake([
            'https://login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response(['access_token' => 'microsoft-token']),
            'https://graph.microsoft.com/oidc/userinfo' => Http::response([
                'sub' => 'microsoft-user-456',
                'name' => 'Microsoft Person',
                'preferred_username' => 'microsoft@example.com',
            ]),
        ]);

        $this->get(route('oauth.callback', ['provider' => 'microsoft', 'state' => $attempt['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'microsoft@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('model_has_roles', [
            'model_type' => User::class,
            'model_id' => $user->id,
        ]);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_invalid_state_is_rejected_without_contacting_provider(): void
    {
        $this->get(route('oauth.redirect', 'google'));
        Http::fake();

        $this->get(route('oauth.callback', ['provider' => 'google', 'state' => 'tampered', 'code' => 'auth-code']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_new_oauth_user_is_rejected_when_registration_is_closed(): void
    {
        Setting::put('auth.allow_registration', 'false');
        $this->get(route('oauth.redirect', 'google'));
        $attempt = session('oauth.google');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'new-google-user',
                'name' => 'New Person',
                'email' => 'new@example.com',
                'email_verified' => true,
            ]),
        ]);

        $this->get(route('oauth.callback', ['provider' => 'google', 'state' => $attempt['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('oauth');

        $this->assertGuest();
        $this->assertSame(0, OAuthIdentity::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_google_new_user_outside_the_domain_allow_list_is_rejected(): void
    {
        Setting::put('auth.allowed_registration_domains', 'example.com');
        $this->get(route('oauth.redirect', 'google'));
        $attempt = session('oauth.google');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-gmail-user',
                'name' => 'Outside Person',
                'email' => 'someone@gmail.com',
                'email_verified' => true,
            ]),
        ]);

        $this->get(route('oauth.callback', ['provider' => 'google', 'state' => $attempt['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['oauth' => __('Please register with your organization email address.')]);

        $this->assertGuest();
        $this->assertSame(0, OAuthIdentity::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'someone@gmail.com']);
    }

    public function test_microsoft_new_user_inside_the_domain_allow_list_is_created(): void
    {
        Setting::put('auth.allowed_registration_domains', 'example.com');
        $this->get(route('oauth.redirect', 'microsoft'));
        $attempt = session('oauth.microsoft');

        Http::fake([
            'https://login.microsoftonline.com/organizations/oauth2/v2.0/token' => Http::response(['access_token' => 'microsoft-token']),
            'https://graph.microsoft.com/oidc/userinfo' => Http::response([
                'sub' => 'microsoft-allowed-user',
                'name' => 'Allowed Person',
                'preferred_username' => 'person@example.com',
            ]),
        ]);

        $this->get(route('oauth.callback', ['provider' => 'microsoft', 'state' => $attempt['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->where('email', 'person@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('submitter'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->is_active);
    }

    public function test_empty_domain_allow_list_still_creates_a_new_oauth_user(): void
    {
        Setting::put('auth.allowed_registration_domains', '');
        $this->get(route('oauth.redirect', 'google'));
        $attempt = session('oauth.google');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-any-domain',
                'name' => 'Any Domain',
                'email' => 'anyone@gmail.com',
                'email_verified' => true,
            ]),
        ]);

        $this->get(route('oauth.callback', ['provider' => 'google', 'state' => $attempt['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertDatabaseHas('users', ['email' => 'anyone@gmail.com']);
    }

    public function test_existing_user_outside_the_allow_list_can_still_link_google(): void
    {
        Setting::put('auth.allowed_registration_domains', 'example.com');
        $user = User::factory()->create(['email' => 'person@gmail.com']);
        $this->get(route('oauth.redirect', 'google'));
        $attempt = session('oauth.google');

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'google-access-token']),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-existing-gmail',
                'name' => 'Staff Gmail',
                'email' => 'person@gmail.com',
                'email_verified' => true,
            ]),
        ]);

        $this->get(route('oauth.callback', ['provider' => 'google', 'state' => $attempt['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('oauth_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-existing-gmail',
        ]);
        $this->assertSame(1, User::query()->where('email', 'person@gmail.com')->count());
    }

    public function test_github_callback_fetches_primary_verified_email_when_profile_email_is_absent(): void
    {
        $user = User::factory()->create(['email' => 'github@example.com']);
        $this->get(route('oauth.redirect', 'github'));
        $attempt = session('oauth.github');

        Http::fake([
            'https://github.com/login/oauth/access_token' => Http::response(['access_token' => 'github-token']),
            'https://api.github.com/user' => Http::response([
                'id' => 9876,
                'name' => 'GitHub Person',
                'login' => 'github-person',
                'email' => null,
                'avatar_url' => 'https://avatars.example.test/9876.png',
            ]),
            'https://api.github.com/user/emails' => Http::response([
                ['email' => 'other@example.com', 'primary' => false, 'verified' => true],
                ['email' => 'github@example.com', 'primary' => true, 'verified' => true],
            ]),
        ]);

        $this->get(route('oauth.callback', ['provider' => 'github', 'state' => $attempt['state'], 'code' => 'auth-code']))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('oauth_identities', [
            'provider' => 'github',
            'provider_user_id' => '9876',
            'email' => 'github@example.com',
        ]);
    }

    public function test_settings_backed_provider_enable_switch_can_disable_environment_credentials(): void
    {
        Setting::put('system.oauth.google.enabled', '0');

        $this->get(route('login'))->assertDontSee('Continue with Google');
        $this->get(route('oauth.redirect', 'google'))->assertNotFound();
    }

    public function test_disabled_or_unknown_provider_is_not_exposed(): void
    {
        config(['services.oauth.google.client_secret' => null]);

        $this->get(route('oauth.redirect', 'google'))->assertNotFound();
        $this->get(route('oauth.redirect', 'unknown'))->assertNotFound();
    }
}
