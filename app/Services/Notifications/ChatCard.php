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

        // When approval links require sign-in and a second factor, chat cards link to
        // the confirmation page instead of approving in one click (RV-SEC-10).
        if (EmailApprovalLinks::requiresSecondFactor()) {
            return [
                'token' => null,
                'confirmUrl' => EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $notifiable),
                'showApprove' => false,
            ];
        }

        return [
            'token' => ChatActionToken::issue($instance, $instance->currentStep, $notifiable, WorkflowActionType::Approve),
            'confirmUrl' => EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $notifiable),
            'showApprove' => true,
        ];
    }
}
