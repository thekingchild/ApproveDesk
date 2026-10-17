<?php

namespace Tests\Feature\Notifications;

use App\Models\Setting;
use App\Services\Notifications\WebPushService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

/** RV-LAR-04: the VAPID private key is encrypted at rest like other settings secrets. */
class WebPushKeyStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_rv_lar_04_generated_private_key_is_stored_encrypted(): void
    {
        $keys = app(WebPushService::class)->vapidKeys();

        $this->assertNull(Setting::get('notifications.web_push.private_key'));
        $stored = (string) Setting::get('notifications.web_push.private_key_encrypted');
        $this->assertStringNotContainsString($keys['privateKey'], $stored);
        $this->assertSame($keys['privateKey'], Crypt::decryptString($stored));
        $this->assertSame($keys, app(WebPushService::class)->vapidKeys(), 'Keys are stable across reads.');
    }

    public function test_rv_lar_04_legacy_plaintext_key_is_kept_and_moved_to_encrypted_storage(): void
    {
        $legacy = VAPID::createVapidKeys();
        Setting::put('notifications.web_push.public_key', $legacy['publicKey']);
        Setting::put('notifications.web_push.private_key', $legacy['privateKey']);

        $this->assertSame($legacy, app(WebPushService::class)->vapidKeys(), 'Existing subscriptions keep working.');
        $this->assertNull(Setting::get('notifications.web_push.private_key'));
        $this->assertSame($legacy['privateKey'], Crypt::decryptString((string) Setting::get('notifications.web_push.private_key_encrypted')));
    }

    public function test_rv_lar_04_key_encrypted_under_another_app_key_is_replaced(): void
    {
        Setting::put('notifications.web_push.public_key', 'old-public');
        Setting::put('notifications.web_push.private_key_encrypted', 'not-decryptable');

        $keys = app(WebPushService::class)->vapidKeys();

        $this->assertNotSame('old-public', $keys['publicKey']);
        $this->assertSame($keys['privateKey'], Crypt::decryptString((string) Setting::get('notifications.web_push.private_key_encrypted')));
    }
}
