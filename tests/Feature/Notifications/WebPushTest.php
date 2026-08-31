<?php

namespace Tests\Feature\Notifications;

use App\DTOs\OutboundNotification;
use App\Enums\NotificationKind;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\WebPushFactory;
use App\Services\Notifications\WebPushService;
use App\Support\OutboundDestination;
use App\Support\OutboundPin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Minishlink\WebPush\WebPush;
use Mockery;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_stored_subscriptions_are_deleted_on_send(): void
    {
        $user = User::factory()->create();
        $loopback = $this->storeSubscription($user, 'https://127.0.0.1/push');
        $plaintext = $this->storeSubscription($user, 'http://hooks.example.test/push');
        $private = $this->storeSubscription($user, 'https://10.0.0.1/push');

        $user->load('pushSubscriptions');
        app(WebPushService::class)->send($user, $this->notification());

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $loopback->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $plaintext->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $private->id]);
    }

    public function test_documentation_range_endpoint_is_rejected_before_queueing(): void
    {
        $user = User::factory()->create();
        $subscription = $this->storeSubscription($user, 'https://203.0.113.10/push');

        try {
            app(OutboundDestination::class)->validate($subscription->endpoint);
            $this->fail('Documentation-range push endpoints must be rejected before queueing.');
        } catch (InvalidArgumentException) {
            // expected: classifier must reject TEST-NET-3 so send() never flushes to it
        }

        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_service_passes_every_validated_pin_to_the_factory_before_queueing(): void
    {
        $user = User::factory()->create();
        $this->storeSubscription($user, 'https://push-one.example.test/push');
        $this->storeSubscription($user, 'https://push-two.example.test/push');
        $user->load('pushSubscriptions');

        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification')->twice()->andReturnSelf();
        $webPush->shouldReceive('flush')->once()->andReturnUsing(static function (): \Generator {
            if (false) {
                yield;
            }
        });

        $seenHosts = [];
        $factory = Mockery::mock(WebPushFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withArgs(function (array $vapid, array $pins) use (&$seenHosts): bool {
                $this->assertArrayHasKey('publicKey', $vapid);
                $this->assertArrayHasKey('privateKey', $vapid);
                $this->assertContainsOnlyInstancesOf(OutboundPin::class, $pins);
                $seenHosts = array_map(static fn (OutboundPin $pin): string => $pin->host, $pins);

                return true;
            })
            ->andReturn($webPush);

        (new WebPushService($factory, app(OutboundDestination::class)))->send($user, $this->notification());

        sort($seenHosts);
        $this->assertSame(['push-one.example.test', 'push-two.example.test'], $seenHosts);
        $this->assertDatabaseCount('push_subscriptions', 2);
    }

    private function storeSubscription(User $user, string $endpoint): PushSubscription
    {
        return $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => str_repeat('B', 88),
            'auth_token' => str_repeat('C', 24),
            'content_encoding' => 'aes128gcm',
        ]);
    }

    private function notification(): OutboundNotification
    {
        return new OutboundNotification(
            kind: NotificationKind::ActionRequired->value,
            subject: 'Action required',
            lines: ['A stored push endpoint was used.'],
        );
    }
}
