<?php

namespace App\Enums;

enum WorkflowActionType: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Return = 'return';
    case RequestInformation = 'request_information';
    case Delegate = 'delegate';
    case Comment = 'comment';
    case Escalate = 'escalate';
    case AutoApprove = 'auto_approve';
    case ConditionMatched = 'condition_matched';
    case ConditionNotMatched = 'condition_not_matched';
}
