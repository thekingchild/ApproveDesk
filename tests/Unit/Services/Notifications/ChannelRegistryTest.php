<?php

namespace Tests\Unit\Services\Notifications;

use App\Enums\NotificationChannelKey;
use App\Enums\NotificationFrequency;
use App\Models\User;
use App\Services\Notifications\ChannelRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChannelRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_includes_every_planned_channel_key(): void
    {
        $keys = app(ChannelRegistry::class)->all()->map(fn ($channel) => $channel->key()->value)->all();

        $this->assertEqualsCanonicalizing([
            'database', 'mail', 'teams', 'slack', 'whatsapp', 'telegram', 'sms', 'web_push',
        ], $keys);
    }

    public function test_sms_and_web_push_stay_disabled(): void
    {
        $registry = app(ChannelRegistry::class);

        $this->assertFalse($registry->driver(NotificationChannelKey::Sms)->isConfigured());
        $this->assertFalse($registry->driver(NotificationChannelKey::Sms)->canReceive($user = User::factory()->create()));
        $this->assertFalse($registry->driver(NotificationChannelKey::WebPush)->canReceive($user));
    }

    public function test_unconfigured_optional_channels_are_omitted_from_via(): void
    {
        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::Immediate]);

        $this->assertSame(['mail', 'database'], app(ChannelRegistry::class)->laravelChannels($user));
    }

    public function test_digest_users_still_only_receive_database_when_optional_channels_are_off(): void
    {
        $user = User::factory()->create(['notification_frequency' => NotificationFrequency::Daily]);

        $this->assertSame(['database'], app(ChannelRegistry::class)->laravelChannels($user));
    }
}
