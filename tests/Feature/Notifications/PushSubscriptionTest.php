<?php

namespace Tests\Feature\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_a_long_push_endpoint_and_refreshing_it_does_not_duplicate(): void
    {
        $user = User::factory()->create();
        $endpoint = 'https://push.example.test/'.str_repeat('a', 1900);

        $payload = [
            'endpoint' => $endpoint,
            'keys' => [
                'p256dh' => str_repeat('B', 88),
                'auth' => str_repeat('C', 24),
            ],
        ];

        $this->actingAs($user)
            ->postJson(route('settings.push-subscriptions.store'), $payload)
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->actingAs($user)
            ->postJson(route('settings.push-subscriptions.store'), [
                ...$payload,
                'keys' => [
                    'p256dh' => str_repeat('D', 88),
                    'auth' => str_repeat('E', 24),
                ],
            ])
            ->assertOk();

        $this->assertSame(1, PushSubscription::query()->where('user_id', $user->id)->count());

        $subscription = PushSubscription::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($subscription);
        $this->assertSame($endpoint, $subscription->endpoint);
        $this->assertSame(hash('sha256', $endpoint), $subscription->endpoint_hash);
        $this->assertSame(str_repeat('D', 88), $subscription->public_key);
    }
}
