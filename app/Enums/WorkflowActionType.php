<?php

namespace App\Enums;

enum WorkflowActionType: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Return = 'return';
    case Delegate = 'delegate';
    case Comment = 'comment';
    case Escalate = 'escalate';
}
