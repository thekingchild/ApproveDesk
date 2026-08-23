<?php

namespace App\Enums;

enum ConnectorKey: string
{
    case Teams = 'teams';
    case Slack = 'slack';
    case GoogleDrive = 'google_drive';
    case SharePoint = 'sharepoint';
    case Mail = 'mail';
    case Http = 'http';

    public function label(): string
    {
        return match ($this) {
            self::Teams => 'Microsoft Teams',
            self::Slack => 'Slack',
            self::GoogleDrive => 'Google Drive',
            self::SharePoint => 'SharePoint / OneDrive',
            self::Mail => 'Gmail / Outlook',
            self::Http => 'Generic HTTP',
        };
    }

    /** @return list<string> */
    public function credentialFields(): array
    {
        return match ($this) {
            self::Teams => ['webhook_url'],
            self::Slack => ['webhook_url'],
            self::GoogleDrive => ['access_token', 'folder_id'],
            self::SharePoint => ['access_token', 'site_url'],
            self::Mail => ['access_token', 'from'],
            self::Http => ['url', 'secret'],
        };
    }

    /** @return list<IntegrationActionType> */
    public function defaultActions(): array
    {
        return match ($this) {
            self::Teams, self::Slack => [IntegrationActionType::SendEmail],
            self::GoogleDrive, self::SharePoint => [IntegrationActionType::UploadFile],
            self::Mail => [IntegrationActionType::SendEmail],
            self::Http => [
                IntegrationActionType::InvokeWebhook,
                IntegrationActionType::UpsertRecord,
                IntegrationActionType::UploadFile,
                IntegrationActionType::SendEmail,
            ],
        };
    }
}
