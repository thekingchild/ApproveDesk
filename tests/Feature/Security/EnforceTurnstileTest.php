<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\User;
use App\Support\Turnstile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class EnforceTurnstileTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-turnstile-secret-value';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_login_and_register_succeed_without_a_token_when_disabled(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->post(route('logout'));

        $this->post(route('register.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_login_succeeds_when_enabled_and_siteverify_passes(): void
    {
        $this->enableTurnstile();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => true], 200),
        ]);
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            Turnstile::TOKEN_FIELD => 'ok-token',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_login_register_and_reset_are_refused_when_enabled_and_token_is_missing_or_rejected(): void
    {
        $this->enableTurnstile();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']], 200),
        ]);
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('register.store'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            Turnstile::TOKEN_FIELD => 'bad-token',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);

        Notification::fake();
        $this->post(route('password.email'), [
            'email' => $user->email,
        ])->assertSessionHasErrors('email');
        Notification::assertNothingSent();
    }

    public function test_password_reset_is_refused_when_enabled_and_token_is_missing(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email]);

        $this->enableTurnstile();
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false], 200),
        ]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])->assertSessionHasErrors('email');

            $this->assertTrue(Hash::check('password', $user->fresh()->password));

            return true;
        });
    }

    public function test_secret_never_appears_in_html_or_logs(): void
    {
        $this->enableTurnstile();
        $logged = [];
        Event::listen(
            MessageLogged::class,
            function (MessageLogged $event) use (&$logged): void {
                $logged[] = $event->message.' '.json_encode($event->context);
            },
        );
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']], 200),
        ]);

        $login = $this->get(route('login'));
        $login->assertOk()
            ->assertSee('challenges.cloudflare.com', false)
            ->assertSee('1x00000000000000000000AA', false)
            ->assertDontSee(self::SECRET, false);

        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)
            ->get(route('settings.security-privacy'))
            ->assertOk()
            ->assertDontSee(self::SECRET, false)
            ->assertSee('Require a Turnstile check');

        $this->post(route('logout'));
        $user = User::factory()->create();
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            Turnstile::TOKEN_FIELD => 'bad-token',
        ]);

        $this->assertNotSame([], $logged);
        $this->assertStringNotContainsString(self::SECRET, implode("\n", $logged));
    }

    public function test_submitter_cannot_change_turnstile_settings(): void
    {
        $submitter = User::factory()->create()->assignRole('submitter');

        $this->actingAs($submitter)
            ->get(route('settings.security-privacy'))
            ->assertForbidden();

        $this->actingAs($submitter)
            ->post(route('settings.turnstile.credentials'), ['secret' => 'stolen'])
            ->assertForbidden();

        $this->actingAs($submitter)
            ->post(route('settings.turnstile.test'))
            ->assertForbidden();
    }

    public function test_administrator_can_disable_and_the_next_login_works_without_a_token(): void
    {
        $this->enableTurnstile();
        $admin = User::factory()->withTwoFactor()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.security-privacy')
            ->set('turnstileEnabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Turnstile::enabled());

        $user = User::factory()->create();
        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_administrator_can_save_secret_and_test_connection(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Http::fake([
            'challenges.cloudflare.com/*' => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']], 200),
        ]);

        $this->actingAs($admin)
            ->post(route('settings.turnstile.credentials'), ['secret' => self::SECRET])
            ->assertRedirect(route('settings.security-privacy'));

        $this->assertTrue(Turnstile::hasSecret());
        $this->assertSame(self::SECRET, Turnstile::secret());

        $this->actingAs($admin)
            ->post(route('settings.turnstile.test'))
            ->assertRedirect(route('settings.security-privacy'))
            ->assertSessionHas('turnstile_test');

        $this->actingAs($admin)
            ->get(route('settings.security-privacy'))
            ->assertOk()
            ->assertSee('Cloudflare answered')
            ->assertDontSee(self::SECRET, false);
    }

    public function test_enabled_turnstile_widens_content_security_policy(): void
    {
        $this->enableTurnstile();

        $csp = (string) $this->get(route('login'))->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://challenges.cloudflare.com', $csp);
    }

    private function enableTurnstile(): void
    {
        Setting::put('turnstile.enabled', '1');
        Setting::put('turnstile.site_key', '1x00000000000000000000AA');
        Setting::put('turnstile.secret', Crypt::encryptString(self::SECRET));
    }
}
