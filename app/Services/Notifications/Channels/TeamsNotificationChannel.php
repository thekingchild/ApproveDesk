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

final class TeamsNotificationChannel implements OptionalNotificationChannel
{
    public function __construct(private readonly ChannelSettings $settings) {}

    public function key(): NotificationChannelKey
    {
        return NotificationChannelKey::Teams;
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
        return is_string($notifiable->teams_user_id) && $notifiable->teams_user_id !== '';
    }

    public function send(User $notifiable, OutboundNotification $message): void
    {
        if (! $this->isEnabled() || ! $this->isConfigured() || ! $this->canReceive($notifiable)) {
            return;
        }

        $credentials = $this->settings->credentials($this->key());

        try {
            $token = $this->accessToken($credentials);
            $response = Http::timeout(10)
                ->withToken($token)
                ->acceptJson()
                ->post('https://graph.microsoft.com/v1.0/users/'.$notifiable->teams_user_id.'/teamwork/sendActivityNotification', [
                    'topic' => [
                        'source' => 'text',
                        'value' => $message->subject,
                        'webUrl' => $message->actionUrl,
                    ],
                    'activityType' => 'systemDefault',
                    'previewText' => ['content' => $message->subject],
                ]);

            if ($response->failed()) {
                $this->settings->recordSendFailure($this->key(), 'Teams send failed: '.$response->status());
            }
        } catch (Throwable $exception) {
            $this->settings->recordSendFailure($this->key(), $exception->getMessage());
        }
    }

    public function testConnection(): ChannelTestResult
    {
        if (! $this->isConfigured()) {
            return new ChannelTestResult(false, 'Add the Teams app ID and password, then test again.');
        }

        try {
            $this->accessToken($this->settings->credentials($this->key()));

            return new ChannelTestResult(true, 'Microsoft Graph accepted the app credentials.');
        } catch (Throwable $exception) {
            return new ChannelTestResult(false, $this->settings->redact($this->key(), $exception->getMessage()));
        }
    }

    /**
     * @param  array<string, string>  $credentials
     */
    private function accessToken(array $credentials): string
    {
        $tenant = $credentials['tenant_id'] ?? 'common';

        $response = Http::asForm()->timeout(10)->post(
            'https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token',
            [
                'client_id' => $credentials['app_id'] ?? '',
                'client_secret' => $credentials['app_password'] ?? '',
                'grant_type' => 'client_credentials',
                'scope' => 'https://graph.microsoft.com/.default',
            ],
        );

        if ($response->failed() || ! is_string($response->json('access_token'))) {
            throw new \RuntimeException('Teams token request failed: '.$response->status());
        }

        return $response->json('access_token');
    }
}
