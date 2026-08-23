<?php

namespace App\Enums;

enum IntegrationActionType: string
{
    case UploadFile = 'upload_file';
    case SendEmail = 'send_email';
    case UpsertRecord = 'upsert_record';
    case InvokeWebhook = 'invoke_webhook';

    public function label(): string
    {
        return match ($this) {
            self::UploadFile => 'Upload file',
            self::SendEmail => 'Send email',
            self::UpsertRecord => 'Create or update external record',
            self::InvokeWebhook => 'Invoke signed webhook',
        };
    }
}
