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

final class SlackNotificationChannel implements OptionalNotificationChannel
{
    public function __construct(private readonly ChannelSettings $settings) {}

    public function key(): NotificationChannelKey
    {
        return NotificationChannelKey::Slack;
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
        return is_string($notifiable->slack_user_id) && $notifiable->slack_user_id !== '';
    }

    public function send(User $notifiable, OutboundNotification $message): void
    {
        if (! $this->isEnabled() || ! $this->isConfigured() || ! $this->canReceive($notifiable)) {
            return;
        }

        $token = $this->settings->credentials($this->key())['bot_token'] ?? '';

        try {
            $response = Http::timeout(10)
                ->withToken($token)
                ->acceptJson()
                ->post('https://slack.com/api/chat.postMessage', [
                    'channel' => $notifiable->slack_user_id,
                    'text' => $message->textBody(),
                ]);

            if ($response->failed() || $response->json('ok') !== true) {
                $this->settings->recordSendFailure(
                    $this->key(),
                    'Slack send failed: '.(string) ($response->json('error') ?? $response->status()),
                );
            }
        } catch (Throwable $exception) {
            $this->settings->recordSendFailure($this->key(), $exception->getMessage());
        }
    }

    public function testConnection(): ChannelTestResult
    {
        if (! $this->isConfigured()) {
            return new ChannelTestResult(false, 'Add a Slack bot token, then test again.');
        }

        $token = $this->settings->credentials($this->key())['bot_token'] ?? '';

        try {
            $response = Http::timeout(10)
                ->withToken($token)
                ->acceptJson()
                ->post('https://slack.com/api/auth.test');

            if ($response->ok() && $response->json('ok') === true) {
                return new ChannelTestResult(true, 'Slack accepted the bot token.');
            }

            return new ChannelTestResult(
                false,
                $this->settings->redact($this->key(), 'Slack test failed: '.(string) ($response->json('error') ?? $response->status())),
            );
        } catch (Throwable $exception) {
            return new ChannelTestResult(false, $this->settings->redact($this->key(), $exception->getMessage()));
        }
    }
}
