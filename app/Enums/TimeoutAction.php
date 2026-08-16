<?php

namespace App\Enums;

enum TimeoutAction: string
{
    case Escalate = 'escalate';
    case AutoApprove = 'auto_approve';
    case AutoReject = 'auto_reject';
}
