<?php

namespace App\Services\Notifications\Channels;

use App\Contracts\OptionalNotificationChannel;
use App\DTOs\ChannelTestResult;
use App\DTOs\OutboundNotification;
use App\Enums\NotificationChannelKey;
use App\Exceptions\NotificationDeliveryException;
use App\Models\User;
use App\Services\Notifications\ChannelSettings;
use App\Services\Notifications\ChatCard;
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
            throw NotificationDeliveryException::skipped('Channel is off, not configured, or the person is not linked.');
        }

        $credentials = $this->settings->credentials($this->key());

        try {
            $token = $this->accessToken($credentials);
            $card = ChatCard::actionFor($notifiable, $message);
            $actions = [];
            if ($card['showApprove'] && is_string($card['token'])) {
                $actions[] = [
                    'type' => 'Action.Http',
                    'title' => 'Approve',
                    'method' => 'POST',
                    'url' => route('integrations.teams.actions'),
                    'body' => json_encode(['token' => $card['token']], JSON_THROW_ON_ERROR),
                    'headers' => [['name' => 'Content-Type', 'value' => 'application/json']],
                ];
            }
            if (is_string($card['confirmUrl'])) {
                $actions[] = [
                    'type' => 'Action.OpenUrl',
                    'title' => 'Open in ApproveDesk',
                    'url' => $card['confirmUrl'],
                ];
            }

            $response = Http::timeout(10)
                ->withToken($token)
                ->acceptJson()
                ->post('https://graph.microsoft.com/v1.0/users/'.$notifiable->teams_user_id.'/teamwork/sendActivityNotification', [
                    'topic' => [
                        'source' => 'text',
                        'value' => $message->subject,
                        'webUrl' => $card['confirmUrl'] ?? $message->actionUrl,
                    ],
                    'activityType' => 'systemDefault',
                    'previewText' => ['content' => $message->subject],
                    'templateParameters' => [
                        ['name' => 'body', 'value' => $message->textBody()],
                    ],
                    'adaptiveCard' => [
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [['type' => 'TextBlock', 'text' => $message->textBody(), 'wrap' => true]],
                        'actions' => $actions,
                    ],
                ]);

            $refused = $response->failed();
        } catch (Throwable $exception) {
            throw NotificationDeliveryException::failed($this->settings->recordSendFailure($this->key(), $exception->getMessage()));
        }

        if ($refused) {
            throw NotificationDeliveryException::failed(
                $this->settings->recordSendFailure($this->key(), 'Teams send failed: '.$response->status()),
            );
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
