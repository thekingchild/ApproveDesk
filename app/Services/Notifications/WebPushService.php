<?php

namespace App\Services\Notifications;

use App\DTOs\OutboundNotification;
use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Throwable;

final class WebPushService
{
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
        $keys = $this->vapidKeys();
        $webPush = new WebPush([
            'VAPID' => [
                'subject' => config('app.url'),
                'publicKey' => $keys['publicKey'],
                'privateKey' => $keys['privateKey'],
            ],
        ]);

        $payload = json_encode([
            'title' => $message->subject,
            'body' => implode(' ', $message->lines),
            'url' => $message->actionUrl ?? route('notifications.index'),
        ], JSON_THROW_ON_ERROR);

        foreach ($user->pushSubscriptions as $row) {
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
