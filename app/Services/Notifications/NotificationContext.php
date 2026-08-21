<?php

namespace App\Services\Notifications;

use App\Models\Submission;
use App\Models\User;
use App\Support\EmailApprovalLinks;

final class NotificationContext
{
    /**
     * @param  array<string, string|null>  $extra
     * @return array<string, string|null>
     */
    public static function forSubmission(Submission $submission, User $recipient, array $extra = []): array
    {
        $submission->loadMissing('submitter');

        $approvalUrl = null;
        $instance = $submission->workflowInstance()->with('currentStep')->first();
        if ($instance?->currentStep) {
            $approvalUrl = EmailApprovalLinks::confirmationUrl($instance, $instance->currentStep, $recipient);
        }

        return array_merge([
            'recipient_name' => $recipient->name,
            'reference_number' => $submission->reference_number,
            'title' => $submission->title,
            'submitter_name' => $submission->submitter?->name,
            'priority' => $submission->priority->value,
            'submission_url' => route('submissions.show', $submission),
            'approval_url' => $approvalUrl,
            'decision' => null,
            'comment' => null,
        ], $extra);
    }
}
