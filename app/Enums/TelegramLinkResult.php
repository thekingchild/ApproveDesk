<?php

namespace App\Enums;

/** What a Telegram "/start {token}" message did (RV-ARC-11). */
enum TelegramLinkResult: string
{
    case Linked = 'linked';
    case UnknownToken = 'unknown_token';
    case TakenByAnotherPerson = 'taken';

    /** The bot's reply in the chat, or null to stay silent. */
    public function reply(): ?string
    {
        return match ($this) {
            self::Linked => 'ApproveDesk is linked. You will receive notifications here when this organisation has Telegram enabled.',
            self::TakenByAnotherPerson => 'This Telegram account is already linked to another ApproveDesk person. Unlink it there first.',
            self::UnknownToken => null,
        };
    }
}
