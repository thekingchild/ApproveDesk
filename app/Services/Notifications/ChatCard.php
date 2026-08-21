<?php

namespace App\Services\Notifications;

use App\DTOs\OutboundNotification;
use App\Enums\WorkflowActionType;
use App\Models\Submission;
use App\Models\User;
use App\Support\EmailApprovalLinks;

final class ChatCard
{
    /**
     * @return array{token: ?string, confirmUrl: ?string, showApprove: bool}
     */
    public static function actionFor(User $notifiable, OutboundNotification $message): array
    {
        $submissionId = $message->payload['submission_id'] ?? null;
        if (! is_numeric($submissionId) || ($message->kind !== 'action_required')) {
            return ['token' => null, 'confirmUrl' => $message->actionUrl, 'showApprove' => false];
        }

        $submission = Submission::query()->find((int) $submissionId);
        $instance = $submission?->workflowInstance()->with('currentStep')->first();
        if ($submission === null || $instance?->currentStep === null) {
            return ['token' => null, 'confirmUrl' => $message->actionUrl, 'showApprove' => false];
        }

        return [
            'token' => ChatActionToken::issue($instance, $instance->currentStep, $notifiable, WorkflowActionType::Approve),
            'confirmUrl' => EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $notifiable),
            'showApprove' => true,
        ];
    }
}
