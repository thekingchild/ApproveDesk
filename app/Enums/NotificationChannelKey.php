<?php

namespace App\Enums;

enum NotificationChannelKey: string
{
    case Database = 'database';
    case Mail = 'mail';
    case Teams = 'teams';
    case Slack = 'slack';
    case WhatsApp = 'whatsapp';
    case Telegram = 'telegram';
    case Sms = 'sms';
    case WebPush = 'web_push';

    public function isGeneric(): bool
    {
        return $this === self::Database || $this === self::Mail;
    }

    public function isAdministratorOption(): bool
    {
        return in_array($this, [self::Teams, self::Slack, self::WhatsApp, self::Telegram], true);
    }

    public function isDeferred(): bool
    {
        return $this === self::Sms || $this === self::WebPush;
    }

    public function label(): string
    {
        return match ($this) {
            self::Database => 'In-app',
            self::Mail => 'Email',
            self::Teams => 'Microsoft Teams',
            self::Slack => 'Slack',
            self::WhatsApp => 'WhatsApp',
            self::Telegram => 'Telegram',
            self::Sms => 'SMS',
            self::WebPush => 'Web push',
        };
    }
}
