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

final class TelegramNotificationChannel implements OptionalNotificationChannel
{
    public function __construct(private readonly ChannelSettings $settings) {}

    public function key(): NotificationChannelKey
    {
        return NotificationChannelKey::Telegram;
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
        return is_string($notifiable->telegram_user_id) && $notifiable->telegram_user_id !== '';
    }

    public function send(User $notifiable, OutboundNotification $message): void
    {
        if (! $this->isEnabled() || ! $this->isConfigured() || ! $this->canReceive($notifiable)) {
            return;
        }

        $token = $this->settings->credentials($this->key())['bot_token'] ?? '';

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $notifiable->telegram_user_id,
                    'text' => $message->textBody(),
                    'disable_web_page_preview' => false,
                ]);

            if ($response->failed() || $response->json('ok') !== true) {
                $this->settings->recordSendFailure($this->key(), 'Telegram send failed: '.$response->status());
            }
        } catch (Throwable $exception) {
            $this->settings->recordSendFailure($this->key(), $exception->getMessage());
        }
    }

    public function testConnection(): ChannelTestResult
    {
        if (! $this->isConfigured()) {
            return new ChannelTestResult(false, 'Add a Telegram bot token, then test again.');
        }

        $token = $this->settings->credentials($this->key())['bot_token'] ?? '';

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get('https://api.telegram.org/bot'.$token.'/getMe');

            if ($response->ok() && $response->json('ok') === true) {
                return new ChannelTestResult(true, 'Telegram accepted the bot token.');
            }

            return new ChannelTestResult(
                false,
                $this->settings->redact($this->key(), 'Telegram test failed: '.$response->status()),
            );
        } catch (Throwable $exception) {
            return new ChannelTestResult(false, $this->settings->redact($this->key(), $exception->getMessage()));
        }
    }
}
