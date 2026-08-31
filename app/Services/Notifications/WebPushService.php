<?php

namespace App\Services\Notifications;

use App\DTOs\OutboundNotification;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use App\Support\OutboundDestination;
use App\Support\OutboundPin;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Throwable;

final class WebPushService
{
    public function __construct(
        private readonly WebPushFactory $factory,
        private readonly OutboundDestination $destination,
    ) {}

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public function vapidKeys(): array
    {
        $public = Setting::get('notifications.web_push.public_key');
        $private = Setting::get('notifications.web_push.private_key');

        if (is_string($public) && $public !== '' && is_string($private) && $private !== '') {
            return ['publicKey' => $public, 'privateKey' => $private];
        }

        $generated = VAPID::createVapidKeys();
        Setting::put('notifications.web_push.public_key', $generated['publicKey']);
        Setting::put('notifications.web_push.private_key', $generated['privateKey']);

        return $generated;
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
