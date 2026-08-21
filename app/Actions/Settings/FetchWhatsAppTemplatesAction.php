<?php

namespace App\Actions\Settings;

use App\Enums\NotificationChannelKey;
use App\Models\WhatsappMessageTemplate;
use App\Services\Notifications\ChannelSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class FetchWhatsAppTemplatesAction
{
    public function __construct(private readonly ChannelSettings $settings) {}

    public function execute(): int
    {
        $credentials = $this->settings->credentials(NotificationChannelKey::WhatsApp);
        $waba = $credentials['waba_id'] ?? '';
        $token = $credentials['access_token'] ?? '';

        if ($waba === '' || $token === '') {
            throw new RuntimeException('WhatsApp is not configured.');
        }

        $response = Http::timeout(20)
            ->withToken($token)
            ->acceptJson()
            ->get('https://graph.facebook.com/v21.0/'.$waba.'/message_templates', [
                'limit' => 250,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('WhatsApp template fetch failed: '.$response->status());
        }

        $count = 0;
        foreach ($response->json('data') ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $components = $row['components'] ?? [];
            WhatsappMessageTemplate::query()->updateOrCreate(
                [
                    'name' => (string) ($row['name'] ?? ''),
                    'language' => (string) ($row['language'] ?? 'en'),
                ],
                [
                    'status' => (string) ($row['status'] ?? ''),
                    'category' => isset($row['category']) ? (string) $row['category'] : null,
                    'components' => $components,
                    'placeholder_count' => $this->countPlaceholders($components),
                    'fetched_at' => now(),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function countPlaceholders(mixed $components): int
    {
        $json = json_encode($components) ?: '';

        return preg_match_all('/\{\{[\w]+\}\}/', $json) ?: 0;
    }
}
