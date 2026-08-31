<?php

namespace Tests\Feature\Notifications;

use App\DTOs\OutboundNotification;
use App\Enums\NotificationKind;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Notifications\WebPushService;
use App\Support\OutboundDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
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
