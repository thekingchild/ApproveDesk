<?php

namespace Tests\Feature\Settings;

use App\Actions\Settings\UpdateSystemSettingsAction;
use App\Models\Setting;
use App\Models\SystemOwner;
use App\Models\User;
use App\Support\SystemSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Setting::query()->delete();
        Artisan::call('cache:clear');
        RateLimiter::clear('system-mail-test:1');
    }

    public function test_capability_defaults_to_administrator_and_can_be_granted_to_a_custom_user(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $custom = User::factory()->create();
        $ordinary = User::factory()->create();

        $this->assertTrue($admin->can('system_settings.manage'));
        $this->assertFalse($ordinary->can('system_settings.manage'));
        $custom->givePermissionTo('system_settings.manage');
        $this->assertTrue($custom->can('system_settings.manage'));
    }

    public function test_system_settings_screen_is_capability_gated_and_password_confirmed(): void
    {
        $owner = User::factory()->create();
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        $denied = User::factory()->create();

        $this->actingAs($denied)->get(route('settings.system'))->assertForbidden();
        $this->actingAs($owner)->get(route('settings.system'))->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('settings.system'))
            ->assertOk()
            ->assertSee('System settings')
            ->assertDontSee('stored-secret-value');
    }

    public function test_mail_secret_is_encrypted_masked_and_can_be_kept_replaced_or_cleared(): void
    {
        config(['mail.mailers.smtp.password' => 'environment-password']);
        $admin = User::factory()->create()->assignRole('admin');
        $session = ['auth.password_confirmed_at' => time()];

        $payload = $this->smtpPayload(['smtp_password_action' => 'replace', 'smtp_password' => 'stored-secret-value']);
        $this->actingAs($admin)->withSession($session)->post(route('settings.system.update'), $payload)->assertRedirect();

        $stored = Setting::query()->where('key', 'system.secret.smtp_password')->value('value');
        $this->assertNotSame('stored-secret-value', $stored);
        $this->assertSame('stored-secret-value', Crypt::decryptString($stored));
        $this->assertTrue(SystemSettings::hasSecret('smtp_password'));
        $this->assertSame('••••••••', SystemSettings::maskedSecret('smtp_password'));

        $this->actingAs($admin)->withSession($session)->post(route('settings.system.update'), $this->smtpPayload())->assertRedirect();
        $this->assertSame('stored-secret-value', SystemSettings::secret('smtp_password', 'environment-password'));

        $this->actingAs($admin)->withSession($session)->post(route('settings.system.update'), $this->smtpPayload(['smtp_password_action' => 'clear']))->assertRedirect();
        $this->assertFalse(SystemSettings::hasSecret('smtp_password'));
        $this->assertSame('environment-password', SystemSettings::secret('smtp_password', 'environment-password'));
    }

    public function test_runtime_mail_configuration_supports_every_allowed_transport_and_environment_fallback(): void
    {
        config(['mail.default' => 'smtp', 'mail.from.address' => 'env@example.test']);
        $this->assertSame('smtp', SystemSettings::mailTransport());
        $this->assertSame('env@example.test', SystemSettings::mailFromAddress());

        $actor = User::factory()->create()->assignRole('admin');
        foreach (['smtp', 'ses', 'postmark', 'resend', 'sendmail'] as $transport) {
            app(UpdateSystemSettingsAction::class)->execute($this->settingsFor($transport), $actor);
            $this->assertSame($transport, config('mail.default'));
            $this->assertSame('system@example.test', config('mail.from.address'));
            $this->assertSame($transport, config("mail.mailers.{$transport}.transport"));
        }
    }

    public function test_log_transport_is_rejected_outside_local_or_testing(): void
    {
        config(['mail.allow_log_transport' => false]);
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.system.update'), $this->basePayload(['mail_transport' => 'log']))
            ->assertSessionHasErrors('mail_transport');
    }

    public function test_session_overrides_are_bounded_applied_and_expire_on_close_hides_remember_me(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.system.update'), $this->basePayload([
                'session_lifetime' => 45,
                'session_expire_on_close' => '1',
            ]))->assertRedirect();

        $this->assertSame(45, config('session.lifetime'));
        $this->assertTrue(config('session.expire_on_close'));

        $this->post(route('logout'));
        $this->get(route('login'))->assertOk()->assertDontSee('Remember me');

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('settings.system.update'), $this->basePayload(['session_lifetime' => 4]))
            ->assertSessionHasErrors('session_lifetime');
    }

    public function test_test_email_is_rate_limited_and_does_not_disclose_transport_failure(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        Mail::fake();
        $session = ['auth.password_confirmed_at' => time()];

        $this->actingAs($admin)->withSession($session)
            ->post(route('settings.system.test-mail'), ['email' => 'admin@example.test'])
            ->assertRedirect()->assertSessionHas('status');
        Mail::assertQueuedCount(1);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin)->withSession($session)
                ->post(route('settings.system.test-mail'), ['email' => 'admin@example.test']);
        }
        $this->actingAs($admin)->withSession($session)
            ->post(route('settings.system.test-mail'), ['email' => 'admin@example.test'])
            ->assertTooManyRequests();
    }

    /** @param array<string, mixed> $overrides */
    private function smtpPayload(array $overrides = []): array
    {
        return $this->basePayload([
            'mail_transport' => 'smtp',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_scheme' => 'tls',
            'smtp_username' => 'mailer',
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function basePayload(array $overrides = []): array
    {
        return [
            'mail_transport' => 'sendmail',
            'mail_from_address' => 'system@example.test',
            'mail_from_name' => 'ApproveDesk Test',
            'session_lifetime' => 120,
            'session_expire_on_close' => '0',
            'google_enabled' => '0',
            'microsoft_enabled' => '0',
            'github_enabled' => '0',
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function settingsFor(string $transport): array
    {
        return $this->basePayload([
            'mail_transport' => $transport,
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_scheme' => 'tls',
            'smtp_username' => 'mailer',
            'ses_region' => 'eu-west-1',
        ]);
    }
}
