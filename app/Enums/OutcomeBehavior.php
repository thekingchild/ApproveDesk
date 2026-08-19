<?php

namespace App\Enums;

enum OutcomeBehavior: string
{
    case Approve = 'approve';
    case Reject = 'reject';
    case Return = 'return';
    case RequestInformation = 'request_information';
    case Escalate = 'escalate';

    public function actionType(): WorkflowActionType
    {
        return match ($this) {
            self::Approve => WorkflowActionType::Approve,
            self::Reject => WorkflowActionType::Reject,
            self::Return => WorkflowActionType::Return,
            self::RequestInformation => WorkflowActionType::RequestInformation,
            self::Escalate => WorkflowActionType::Escalate,
        };
    }
}
