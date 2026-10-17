<?php

namespace App\Actions\Settings;

use App\Enums\NotificationChannelKey;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class SaveUserChannelIdentityAction
{
    public function execute(User $user, NotificationChannelKey $channel, ?string $externalId, ?User $actor = null): void
    {
        // The owner may link their own chat account; nobody else may map it.
        if ($user->isSystemOwner() && ($actor === null || ! $actor->is($user))) {
            throw new InvalidArgumentException('The System owner cannot be mapped from user administration.');
        }

        // A chat identity can approve through one-click cards, so remapping someone
        // else's follows the same rule as changing their email (RV-SEC-10 residual).
        if ($actor !== null && ! $actor->is($user) && ! Gate::forUser($actor)->allows('manageIdentity', $user)) {
            throw new InvalidArgumentException('Only the System owner can change the chat identity of someone with more access than you.');
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

        $previous = $user->getAttribute($column);
        $user->forceFill([$column => $value])->save();

        if ($actor !== null && $previous !== $value) {
            activity()->performedOn($user)->causedBy($actor)
                ->event('channel-identity-changed')
                ->withProperties(['channel' => $channel->value, 'had_previous' => $previous !== null, 'cleared' => $value === null])
                ->log('Chat identity mapping changed');
        }
    }
}
