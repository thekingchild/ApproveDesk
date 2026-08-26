<?php

namespace App\Actions\Settings;

use App\Enums\NotificationChannelKey;
use App\Models\User;
use InvalidArgumentException;

final class SaveUserChannelIdentityAction
{
    public function execute(User $user, NotificationChannelKey $channel, ?string $externalId): void
    {
        if ($user->isSystemOwner()) {
            throw new InvalidArgumentException('The System owner cannot be mapped from user administration.');
        }

        $column = match ($channel) {
            NotificationChannelKey::Teams => 'teams_user_id',
            NotificationChannelKey::Slack => 'slack_user_id',
            NotificationChannelKey::Telegram => 'telegram_user_id',
            default => throw new InvalidArgumentException('This channel does not store a mapped identity on the user.'),
        };

        $value = $externalId === null ? null : trim($externalId);
        if ($value === '') {
            $value = null;
        }

        if ($value !== null && strlen($value) > 64) {
            throw new InvalidArgumentException('Channel identity is too long.');
        }

        if ($value !== null && User::query()->where($column, $value)->where('id', '!=', $user->id)->exists()) {
            throw new InvalidArgumentException('That identity is already mapped to another person.');
        }

        $user->forceFill([$column => $value])->save();
    }
}
