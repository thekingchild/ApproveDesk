<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannelKey;
use App\Services\Notifications\ChannelSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Telegram inbound webhook. Shared hosting cannot long-poll getUpdates.
 * P8-NOT-01 only acknowledges updates; linking and notify-only send live
 * through identity mapping and sendMessage. No workflow mutations here.
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

        return response()->noContent();
    }
}
