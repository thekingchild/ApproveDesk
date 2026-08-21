<?php

namespace Tests\Feature\Settings;

use App\Enums\NotificationChannelKey;
use App\Enums\NotificationFrequency;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\ActionRequiredNotification;
use App\Services\Notifications\ChannelRegistry;
use App\Services\Notifications\ChannelSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class NotificationChannelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_channel_settings_page_requires_permission(): void
    {
        $this->get(route('settings.notification-channels'))->assertRedirect('/login');

        $this->actingAs(User::factory()->create()->assignRole('viewer'))
            ->get(route('settings.notification-channels'))
            ->assertForbidden();

        $this->actingAs(User::factory()->create()->assignRole('admin'))
            ->get(route('settings.notification-channels'))
            ->assertOk()
            ->assertSee('Notification channels')
            ->assertSee('Microsoft Teams')
            ->assertSee('People')
            ->assertDontSee('xoxb-secret-token');
    }

    public function test_administrator_can_enable_a_channel_without_binding_tokens_on_livewire(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->set('slack_enabled', true)
            ->call('saveFlags')
            ->assertHasNoErrors();

        $this->assertSame('true', Setting::get('notifications.slack.enabled'));

        $html = Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->html();

        $this->assertStringNotContainsString('wire:model="bot_token"', $html);
        $this->assertStringNotContainsString('wire:model="access_token"', $html);
        $this->assertStringNotContainsString('wire:model="app_password"', $html);
    }

    public function test_credentials_are_encrypted_and_omitted_from_the_settings_page(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->actingAs($admin)
            ->post(route('settings.notification-channels.credentials'), [
                'channel' => 'slack',
                'bot_token' => 'xoxb-super-secret',
                'signing_secret' => 'signing-secret',
            ])
            ->assertRedirect(route('settings.notification-channels'));

        $stored = Setting::get('notifications.slack.credentials');
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('xoxb-super-secret', (string) $stored);
        $decoded = json_decode(Crypt::decryptString((string) $stored), true);
        $this->assertIsArray($decoded);
        $this->assertSame('xoxb-super-secret', $decoded['bot_token']);

        $this->actingAs($admin)
            ->get(route('settings.notification-channels'))
            ->assertOk()
            ->assertDontSee('xoxb-super-secret')
            ->assertDontSee('signing-secret');
    }

    public function test_unconfigured_optional_channels_do_not_replace_mail_and_database(): void
    {
        Notification::fake();
        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::Immediate]);
        $notification = new ActionRequiredNotification(Submission::factory()->create());

        $this->assertSame(['mail', 'database'], $notification->via($user));

        $user->notify($notification);

        Notification::assertSentTo($user, ActionRequiredNotification::class);
    }

    public function test_enabled_slack_without_identity_is_skipped(): void
    {
        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Slack, true);
        $settings->putCredentials(NotificationChannelKey::Slack, ['bot_token' => 'xoxb-test']);

        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::Immediate]);

        $this->assertSame(['mail', 'database'], app(ChannelRegistry::class)->laravelChannels($user));
    }

    public function test_configured_and_mapped_slack_is_appended_and_send_is_fail_open(): void
    {
        Http::fake([
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
        ]);

        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Slack, true);
        $settings->putCredentials(NotificationChannelKey::Slack, ['bot_token' => 'xoxb-test']);

        $user = User::factory()->create([
            'notification_frequency' => NotificationFrequency::InAppOnly,
            'slack_user_id' => 'U123',
        ]);
        $notification = new ActionRequiredNotification(Submission::factory()->create());

        $this->assertSame(['database', 'slack'], $notification->via($user));

        $user->notify($notification);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat.postMessage')
            && $request->hasHeader('Authorization', 'Bearer xoxb-test'));
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'notifiable_type' => User::class,
        ]);
    }

    public function test_whatsapp_does_not_post_free_form_text(): void
    {
        Http::fake();

        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::WhatsApp, true);
        $settings->putCredentials(NotificationChannelKey::WhatsApp, [
            'waba_id' => '123',
            'phone_number_id' => '456',
            'access_token' => 'wa-secret',
        ]);

        $user = User::factory()->create([
            'notification_frequency' => NotificationFrequency::InAppOnly,
            'phone' => '+15551234567',
        ]);

        $this->assertSame(
            ['database', 'whatsapp'],
            app(ChannelRegistry::class)->laravelChannels($user),
        );

        $user->notify(new ActionRequiredNotification(Submission::factory()->create()));

        Http::assertNothingSent();
    }

    public function test_slack_test_connection_records_success_without_logging_the_token(): void
    {
        Http::fake([
            'slack.com/api/auth.test' => Http::response(['ok' => true]),
        ]);

        $admin = User::factory()->create()->assignRole('admin');
        $this->actingAs($admin)->post(route('settings.notification-channels.credentials'), [
            'channel' => 'slack',
            'bot_token' => 'xoxb-test-token',
        ]);

        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->call('test', 'slack')
            ->assertHasNoErrors();

        $this->assertNotNull(Setting::get('notifications.slack.last_tested_at'));
        $this->assertNull(Setting::get('notifications.slack.last_error'));
    }

    public function test_identity_mapping_can_be_saved_and_cleared(): void
    {
        $admin = User::factory()->create()->assignRole('admin');
        $person = User::factory()->create();

        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->set('mapping_user_id', $person->id)
            ->set('mapping_channel', 'telegram')
            ->set('mapping_external_id', '777')
            ->call('saveMapping')
            ->assertHasNoErrors();

        $this->assertSame('777', $person->fresh()->telegram_user_id);

        Livewire::actingAs($admin)
            ->test('pages::settings.notification-channels')
            ->set('mapping_user_id', $person->id)
            ->set('mapping_channel', 'telegram')
            ->set('mapping_external_id', '')
            ->call('saveMapping')
            ->assertHasNoErrors();

        $this->assertNull($person->fresh()->telegram_user_id);
    }

    public function test_telegram_webhook_is_a_post_route_and_does_not_long_poll(): void
    {
        $this->post(route('integrations.telegram.webhook'))->assertNotFound();

        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Telegram, true);
        $settings->putCredentials(NotificationChannelKey::Telegram, [
            'bot_token' => '123:abc',
            'webhook_secret' => 'hook-secret',
        ]);

        $this->post(route('integrations.telegram.webhook'), ['update_id' => 1])
            ->assertForbidden();

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])
            ->post(route('integrations.telegram.webhook').'?getUpdates=1', ['update_id' => 1])
            ->assertNotFound();

        $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'hook-secret'])
            ->post(route('integrations.telegram.webhook'), ['update_id' => 1])
            ->assertNoContent();

        $driver = file_get_contents(app_path('Services/Notifications/Channels/TelegramNotificationChannel.php'));
        $this->assertIsString($driver);
        $this->assertStringNotContainsString('getUpdates', $driver);
        $this->assertStringNotContainsString('long-poll', $driver);
    }

    public function test_in_app_only_still_suppresses_email_but_not_a_mapped_optional_channel(): void
    {
        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Slack, true);
        $settings->putCredentials(NotificationChannelKey::Slack, ['bot_token' => 'xoxb-test']);

        $user = User::factory()->create([
            'notification_frequency' => NotificationFrequency::InAppOnly,
            'slack_user_id' => 'U9',
        ]);

        $this->assertSame(['database', 'slack'], app(ChannelRegistry::class)->laravelChannels($user));
    }
}
