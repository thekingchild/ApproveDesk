<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannelKey;
use App\Models\User;
use App\Services\Notifications\ChannelSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Telegram inbound webhook. Shared hosting cannot long-poll getUpdates.
 * Linking uses /start {token}. Notify-only: no workflow mutations.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, ChannelSettings $settings): Response
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
            $token = trim(substr($text, 7));
            $userId = Cache::pull('telegram-link:'.$token);
            if (is_numeric($userId)) {
                $user = User::query()->find((int) $userId);
                if ($user) {
                    $user->forceFill(['telegram_user_id' => (string) $fromId])->save();
                    $bot = $settings->credentials($channel)['bot_token'] ?? '';
                    if ($bot !== '') {
                        Http::timeout(10)->acceptJson()->post('https://api.telegram.org/bot'.$bot.'/sendMessage', [
                            'chat_id' => $fromId,
                            'text' => 'ApproveDesk is linked. You will receive notifications here when this organisation has Telegram enabled.',
                        ]);
                    }
                }
            }
        }

        return response()->noContent();
    }
}
