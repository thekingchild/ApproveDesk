<?php

namespace App\Actions\Settings;

use App\Enums\NotificationKind;
use App\Models\NotificationEmailTemplate;
use App\Models\User;
use App\Services\Notifications\NotificationCopy;
use InvalidArgumentException;

final class SaveNotificationEmailTemplateAction
{
    public function __construct(private readonly NotificationCopy $copy) {}

    public function execute(NotificationKind $kind, string $subject, string $body, User $actor): NotificationEmailTemplate
    {
        $subject = trim($subject);
        $body = trim($body);

        if ($subject === '' || $body === '') {
            throw new InvalidArgumentException('Subject and body are required.');
        }

        if ($this->copy->requiresApprovalUrl($kind, $body)) {
            throw new InvalidArgumentException('Action-required templates must include {{approval_url}} so the signed confirmation link is kept.');
        }

        return NotificationEmailTemplate::query()->updateOrCreate(
            ['kind' => $kind->value],
            [
                'subject' => $subject,
                'body' => $body,
                'is_custom' => true,
                'updated_by' => $actor->id,
            ],
        );
    }

    public function restore(NotificationKind $kind): void
    {
        NotificationEmailTemplate::query()->where('kind', $kind->value)->delete();
    }
}
