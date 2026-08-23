<?php

namespace Tests\Feature\Notifications;

use App\Enums\NotificationChannelKey;
use App\Enums\NotificationFrequency;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\ActionRequiredNotification;
use App\Services\Notifications\ChannelSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramOutboundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_linked_user_receives_send_message_on_the_faked_bot_api(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Telegram, true);
        $settings->putCredentials(NotificationChannelKey::Telegram, [
            'bot_token' => '123:abc',
            'webhook_secret' => 'hook-secret',
        ]);

        $user = User::factory()->create([
            'notification_frequency' => NotificationFrequency::InAppOnly,
            'telegram_user_id' => '777',
        ]);
        $user->notify(new ActionRequiredNotification(
            Submission::factory()->create(['title' => 'Capex review']),
        ));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org/bot123:abc/sendMessage')
            && $request['chat_id'] === '777'
            && str_contains((string) $request['text'], 'Capex review'));
    }

    public function test_unlinked_user_does_not_call_telegram(): void
    {
        Http::fake();

        $settings = app(ChannelSettings::class);
        $settings->setEnabled(NotificationChannelKey::Telegram, true);
        $settings->putCredentials(NotificationChannelKey::Telegram, [
            'bot_token' => '123:abc',
            'webhook_secret' => 'hook-secret',
        ]);

        $user = User::factory()->create([
            'notification_frequency' => NotificationFrequency::InAppOnly,
            'telegram_user_id' => null,
        ]);
        $user->notify(new ActionRequiredNotification(Submission::factory()->create()));

        Http::assertNothingSent();
    }

    public function test_telegram_driver_does_not_long_poll(): void
    {
        $driver = file_get_contents(app_path('Services/Notifications/Channels/TelegramNotificationChannel.php'));

        $this->assertIsString($driver);
        $this->assertStringContainsString('sendMessage', $driver);
        $this->assertStringNotContainsString('getUpdates', $driver);
        $this->assertStringNotContainsString('long-poll', $driver);
        $this->assertStringNotContainsString('long_poll', $driver);
    }
}
