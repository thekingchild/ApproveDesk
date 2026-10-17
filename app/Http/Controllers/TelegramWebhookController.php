<?php

namespace App\Http\Controllers;

use App\Actions\Notifications\LinkTelegramAccountAction;
use App\Enums\NotificationChannelKey;
use App\Services\Notifications\Channels\TelegramNotificationChannel;
use App\Services\Notifications\ChannelSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Telegram inbound webhook. Shared hosting cannot long-poll getUpdates.
 * Linking uses /start {token} (LinkTelegramAccountAction). Notify-only: no
 * workflow mutations.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, ChannelSettings $settings, LinkTelegramAccountAction $link, TelegramNotificationChannel $telegram): Response
    {
        $channel = NotificationChannelKey::Telegram;

        abort_unless($settings->isEnabled($channel) && $settings->hasCredentials($channel), 404);

        $secret = $settings->credentials($channel)['webhook_secret'] ?? '';
        if ($secret !== '') {
            abort_unless(hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);
        }

        abort_if($request->query('getUpdates') !== null, 404);

        $text = (string) data_get($request->all(), 'message.text', '');
        $fromId = data_get($request->all(), 'message.from.id');

        if (str_starts_with($text, '/start ') && is_numeric($fromId)) {
            $reply = $link->execute(trim(substr($text, 7)), (string) $fromId)->reply();
            if ($reply !== null) {
                $telegram->sendText((string) $fromId, $reply);
            }
        }

        return response()->noContent();
    }
}
