<?php

namespace App\Services\Notifications;

use App\DTOs\OutboundNotification;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Support\OutboundDestination;
use App\Support\OutboundPin;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Throwable;

final class WebPushService
{
    public function __construct(
        private readonly WebPushFactory $factory,
        private readonly OutboundDestination $destination,
    ) {}

    /** Encrypted at rest like every other settings secret (RV-LAR-04). */
    private const PRIVATE_KEY = 'notifications.web_push.private_key_encrypted';

    /** Plaintext key written before RV-LAR-04; moved to PRIVATE_KEY on first read. */
    private const LEGACY_PRIVATE_KEY = 'notifications.web_push.private_key';

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public function vapidKeys(): array
    {
        $public = Setting::get('notifications.web_push.public_key');
        $private = $this->storedPrivateKey();

        if (is_string($public) && $public !== '' && $private !== null) {
            return ['publicKey' => $public, 'privateKey' => $private];
        }

        $generated = VAPID::createVapidKeys();
        Setting::put('notifications.web_push.public_key', $generated['publicKey']);
        $this->storePrivateKey($generated['privateKey']);

        return $generated;
    }

    private function storedPrivateKey(): ?string
    {
        $encrypted = Setting::get(self::PRIVATE_KEY);
        if (is_string($encrypted) && $encrypted !== '') {
            try {
                return Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                // Encrypted with another APP_KEY (e.g. a restore onto a new install):
                // new keys are generated and browsers re-subscribe.
                return null;
            }
        }

        $legacy = Setting::get(self::LEGACY_PRIVATE_KEY);
        if (! is_string($legacy) || $legacy === '') {
            return null;
        }

        $this->storePrivateKey($legacy);

        return $legacy;
    }

    private function storePrivateKey(string $privateKey): void
    {
        Setting::put(self::PRIVATE_KEY, Crypt::encryptString($privateKey));
        Setting::forget(self::LEGACY_PRIVATE_KEY);
    }

    public function publicKey(): string
    {
        return $this->vapidKeys()['publicKey'];
    }

    public function send(User $user, OutboundNotification $message): void
    {
        /** @var list<array{PushSubscription, OutboundPin}> $validated */
        $validated = [];

        foreach ($user->pushSubscriptions as $row) {
            try {
                $validated[] = [$row, $this->destination->validate($row->endpoint)];
            } catch (Throwable) {
                $row->delete();
            }
        }

        if ($validated === []) {
            return;
        }

        $keys = $this->vapidKeys();
        $pins = array_map(static fn (array $entry): OutboundPin => $entry[1], $validated);
        $webPush = $this->factory->make([
            'subject' => (string) config('app.url'),
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
        ], $pins);

        $payload = json_encode([
            'title' => $message->subject,
            'body' => implode(' ', $message->lines),
            'url' => $message->actionUrl ?? route('notifications.index'),
        ], JSON_THROW_ON_ERROR);

        foreach ($validated as [$row]) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $row->endpoint,
                        'publicKey' => $row->public_key,
                        'authToken' => $row->auth_token,
                        'contentEncoding' => $row->content_encoding,
                    ]),
                    $payload,
                );
            } catch (Throwable) {
                $row->delete();
            }
        }

        foreach ($webPush->flush() as $report) {
            if (! $report->isSuccess()) {
                PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }
}
