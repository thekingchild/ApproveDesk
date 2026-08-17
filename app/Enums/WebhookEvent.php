<?php

namespace App\Enums;

enum WebhookEvent: string
{
    case SubmissionCreated = 'submission.created';
    case WorkflowAdvanced = 'workflow.advanced';
    case WorkflowCompleted = 'workflow.completed';
    case WorkflowRejected = 'workflow.rejected';
    case WorkflowReturned = 'workflow.returned';
    case WorkflowInformationRequested = 'workflow.information_requested';
    case WorkflowDelegated = 'workflow.delegated';
}
