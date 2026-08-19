<?php

namespace App\Enums;

enum WorkflowActionType: string
{
    case Approve = 'approve';
    case ApproveWithConditions = 'approve_with_conditions';
    case Reject = 'reject';
    case Return = 'return';
    case RequestInformation = 'request_information';
    case Delegate = 'delegate';
    case Comment = 'comment';
    case Escalate = 'escalate';
    case AutoApprove = 'auto_approve';
    case AutoReject = 'auto_reject';
    case ConditionMatched = 'condition_matched';
    case ConditionNotMatched = 'condition_not_matched';
}
