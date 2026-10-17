<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\OptionalNotificationChannel;
use App\DTOs\ChannelTestResult;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationChannelKey;
use App\Exceptions\NotificationDeliveryException;
use App\Models\User;
use App\Models\WhatsappMessageTemplate;
use App\Models\WhatsappNotificationMap;
use App\Services\Notifications\ChannelSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp outbound is template-mapped (P8-NOT-04). Until a mapping exists,
 * send() skips (and says so) rather than posting free-form text.
 */
final class WhatsAppNotificationChannel implements OptionalNotificationChannel
{
    public function __construct(private readonly ChannelSettings $settings) {}

    public function key(): NotificationChannelKey
    {
        return NotificationChannelKey::WhatsApp;
    }

    public function isEnabled(): bool
    {
        return $this->settings->isEnabled($this->key());
    }

    public function isConfigured(): bool
    {
        return $this->settings->hasCredentials($this->key());
    }

    public function canReceive(User $notifiable): bool
    {
        $phone = $notifiable->phone;

        return is_string($phone) && preg_match('/^\+[1-9]\d{7,14}$/', $phone) === 1;
    }

    public function send(User $notifiable, OutboundNotification $message): void
    {
        if (! $this->isEnabled() || ! $this->isConfigured() || ! $this->canReceive($notifiable)) {
            throw NotificationDeliveryException::skipped('Channel is off, not configured, or the person is not linked.');
        }

        $map = WhatsappNotificationMap::query()->where('kind', $message->kind)->first();
        $catalog = $map === null ? null : WhatsappMessageTemplate::query()
            ->where('name', $map->template_name)
            ->where('language', $map->language)
            ->first();

        // No approved template for this kind: nothing was sent, so the row says Skipped (RV-BUG-15).
        if ($map === null || ! $map->is_healthy || $catalog === null || ! $catalog->isApproved()) {
            throw NotificationDeliveryException::skipped('No approved WhatsApp template is mapped to this notification type.');
        }

        $credentials = $this->settings->credentials($this->key());
        $parameters = [];
        $context = array_merge($message->payload, [
            'recipient_name' => $notifiable->name,
            'title' => $message->payload['title'] ?? $message->subject,
        ]);
        if ($message->actionUrl) {
            $context['submission_url'] = $message->actionUrl;
            $context['approval_url'] = $message->actionUrl;
        }

        foreach ($map->bindings as $slot => $placeholder) {
            $parameters[] = [
                'type' => 'text',
                'text' => (string) ($context[$slot] ?? $message->subject),
            ];
        }

        try {
            $response = Http::timeout(15)
                ->withToken($credentials['access_token'] ?? '')
                ->acceptJson()
                ->post('https://graph.facebook.com/v21.0/'.($credentials['phone_number_id'] ?? '').'/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => ltrim((string) $notifiable->phone, '+'),
                    'type' => 'template',
                    'template' => [
                        'name' => $map->template_name,
                        'language' => ['code' => $map->language],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => $parameters,
                        ]],
                    ],
                ]);

            $refused = $response->failed();
        } catch (Throwable $exception) {
            throw NotificationDeliveryException::failed($this->settings->recordSendFailure($this->key(), $exception->getMessage()));
        }

        if ($refused) {
            $map->forceFill(['is_healthy' => false])->save();

            throw NotificationDeliveryException::failed(
                $this->settings->recordSendFailure($this->key(), 'WhatsApp send failed: '.$response->status()),
            );
        }
    }

    public function testConnection(): ChannelTestResult
    {
        if (! $this->isConfigured()) {
            return new ChannelTestResult(false, 'Add the WhatsApp business account, phone number id, and access token, then test again.');
        }

        $credentials = $this->settings->credentials($this->key());

        try {
            $response = Http::timeout(10)
                ->withToken($credentials['access_token'] ?? '')
                ->acceptJson()
                ->get('https://graph.facebook.com/v21.0/'.($credentials['phone_number_id'] ?? ''));

            if ($response->successful()) {
                return new ChannelTestResult(true, 'WhatsApp Cloud API accepted the credentials.');
            }

            return new ChannelTestResult(
                false,
                $this->settings->redact($this->key(), 'WhatsApp test failed: '.$response->status()),
            );
        } catch (Throwable $exception) {
            return new ChannelTestResult(false, $this->settings->redact($this->key(), $exception->getMessage()));
        }
    }
}
