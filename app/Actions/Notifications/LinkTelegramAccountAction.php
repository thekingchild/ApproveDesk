<?php

namespace App\Actions\Notifications;

use App\Actions\Settings\SaveUserChannelIdentityAction;
use App\Enums\NotificationChannelKey;
use App\Enums\TelegramLinkResult;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Links the Telegram account that sent "/start {token}" to the person who
 * opened that link from Settings → Notifications (RV-ARC-11). Tokens are
 * single use and expire after two hours. The identity rules are the same as
 * for an administrator's mapping: one Telegram account per person, recorded
 * in the activity log.
 */
final class LinkTelegramAccountAction
{
    public const CACHE_PREFIX = 'telegram-link:';

    public function __construct(private readonly SaveUserChannelIdentityAction $identities) {}

    /** A new single-use token for $user; the bot link carries it as ?start=. */
    public static function issueToken(User $user): string
    {
        $token = bin2hex(random_bytes(16));
        Cache::put(self::CACHE_PREFIX.$token, $user->id, now()->addHours(2));

        return $token;
    }

    public function execute(string $token, string $telegramUserId): TelegramLinkResult
    {
        $userId = $token === '' ? null : Cache::pull(self::CACHE_PREFIX.$token);
        $user = is_numeric($userId) ? User::query()->where('is_active', true)->find((int) $userId) : null;

        if (! $user instanceof User) {
            return TelegramLinkResult::UnknownToken;
        }

        try {
            $this->identities->execute($user, NotificationChannelKey::Telegram, $telegramUserId, $user);
        } catch (InvalidArgumentException) {
            return TelegramLinkResult::TakenByAnotherPerson;
        }

        return TelegramLinkResult::Linked;
    }
}
