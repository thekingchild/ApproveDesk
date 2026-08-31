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
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Mockery;
use Tests\Support\ArrayDnsResolver;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_only_subscriptions_are_deleted_without_creating_a_client(): void
    {
        $user = User::factory()->create();
        $loopback = $this->storeSubscription($user, 'https://127.0.0.1/push');
        $plaintext = $this->storeSubscription($user, 'http://hooks.example.test/push');
        $private = $this->storeSubscription($user, 'https://10.0.0.1/push');
        $factory = Mockery::mock(WebPushFactory::class);
        $factory->shouldNotReceive('make');

        $user->load('pushSubscriptions');
        (new WebPushService($factory, app(OutboundDestination::class)))->send($user, $this->notification());

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $loopback->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $plaintext->id]);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $private->id]);
    }

    public function test_trailing_dot_subscription_is_deleted_without_creating_an_unpinned_client(): void
    {
        $user = User::factory()->create();
        $subscription = $this->storeSubscription($user, 'https://push.example.test./push');
        $factory = Mockery::mock(WebPushFactory::class);
        $factory->shouldNotReceive('make');

        $user->load('pushSubscriptions');
        (new WebPushService($factory, app(OutboundDestination::class)))->send($user, $this->notification());

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_mixed_subscriptions_delete_invalid_and_queue_only_valid_with_its_pin(): void
    {
        $user = User::factory()->create();
        $invalid = $this->storeSubscription($user, 'https://10.0.0.1/push');
        $valid = $this->storeSubscription($user, 'https://push.example.test/delivery');
        $user->load('pushSubscriptions');

        $webPush = Mockery::mock(WebPush::class);
        $webPush->shouldReceive('queueNotification')
            ->once()
            ->withArgs(fn (Subscription $subscription, string $payload): bool => $subscription->getEndpoint() === $valid->endpoint && $payload !== '');
        $webPush->shouldReceive('flush')->once()->andReturnUsing(static function (): \Generator {
            yield from [];
        });

        $factory = Mockery::mock(WebPushFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->withArgs(function (array $vapid, array $pins): bool {
                return count($pins) === 1
                    && $pins[0] instanceof OutboundPin
                    && $pins[0]->host === 'push.example.test'
                    && $pins[0]->ip === '93.184.216.34';
            })
            ->andReturn($webPush);

        (new WebPushService($factory, app(OutboundDestination::class)))->send($user, $this->notification());

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $invalid->id]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $valid->id]);
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

    public function test_service_uses_concrete_factory_and_mock_transport_for_a_successful_delivery(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(201)]));
        $stack->push(Middleware::history($history));
        $factory = new WebPushFactory($stack);
        $destination = new OutboundDestination(new ArrayDnsResolver([
            'push.example.test' => ['93.184.216.34'],
        ], []));
        $endpoint = 'https://push.example.test/delivery';
        $pin = $destination->validate($endpoint);
        $options = $factory->clientOptions($pin);

        $this->assertTrue($options['verify']);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(['push.example.test:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame($stack, $options['handler']);

        $user = User::factory()->create();
        $subscription = $this->storeValidSubscription($user, $endpoint);
        $user->load('pushSubscriptions');

        (new WebPushService($factory, $destination))->send($user, $this->notification());

        $this->assertCount(1, $history);
        $this->assertSame('POST', $history[0]['request']->getMethod());
        $this->assertSame($endpoint, (string) $history[0]['request']->getUri());
        $this->assertDatabaseHas('push_subscriptions', ['id' => $subscription->id]);
    }

    public function test_failed_delivery_report_deletes_the_matching_valid_subscription(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(410)]));
        $stack->push(Middleware::history($history));
        $destination = new OutboundDestination(new ArrayDnsResolver([
            'push.example.test' => ['93.184.216.34'],
        ], []));
        $endpoint = 'https://push.example.test/expired';
        $user = User::factory()->create();
        $subscription = $this->storeValidSubscription($user, $endpoint);
        $user->load('pushSubscriptions');

        (new WebPushService(new WebPushFactory($stack), $destination))->send($user, $this->notification());

        $this->assertCount(1, $history);
        $this->assertDatabaseMissing('push_subscriptions', ['id' => $subscription->id]);
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

    private function storeValidSubscription(User $user, string $endpoint): PushSubscription
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $details = openssl_pkey_get_details($key);
        $this->assertNotFalse($details);
        $this->assertArrayHasKey('ec', $details);
        $this->assertIsArray($details['ec']);
        $this->assertArrayHasKey('x', $details['ec']);
        $this->assertArrayHasKey('y', $details['ec']);

        $publicKey = "\x04"
            .str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT)
            .str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $this->assertSame(65, strlen($publicKey));

        return $user->pushSubscriptions()->create([
            'endpoint' => $endpoint,
            'public_key' => $this->base64UrlEncode($publicKey),
            'auth_token' => $this->base64UrlEncode(random_bytes(16)),
            'content_encoding' => 'aes128gcm',
        ]);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
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
