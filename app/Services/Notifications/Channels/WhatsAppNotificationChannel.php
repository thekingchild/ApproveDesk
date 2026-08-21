<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\OptionalNotificationChannel;
use App\DTOs\ChannelTestResult;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationChannelKey;
use App\Models\User;
use App\Services\Notifications\ChannelSettings;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * WhatsApp outbound is template-mapped (P8-NOT-04). Until a mapping exists,
 * send() skips rather than posting free-form text.
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
        // Template mapping and Graph sends land in P8-NOT-04. Never send type=text.
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
