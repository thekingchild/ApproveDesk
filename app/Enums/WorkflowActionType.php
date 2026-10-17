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
    case Withdraw = 'withdraw';
    case DelegationRevoked = 'delegation_revoked';

    /** Decisions that must explain themselves; the engine refuses them without a comment. */
    public function requiresComment(): bool
    {
        return in_array($this, [self::ApproveWithConditions, self::Reject, self::Return, self::RequestInformation, self::Escalate], true);
    }

    public function label(): string
    {
        return __(str_replace('_', ' ', $this->value));
    }

    /** Badge colour in the action history. */
    public function color(): string
    {
        return match ($this) {
            self::Approve, self::ApproveWithConditions, self::AutoApprove => 'lime',
            self::Reject, self::AutoReject => 'red',
            self::Return, self::RequestInformation => 'amber',
            self::ConditionMatched, self::Delegate, self::Escalate => 'blue',
            self::ConditionNotMatched, self::Comment, self::DelegationRevoked, self::Withdraw => 'zinc',
        };
    }
}
