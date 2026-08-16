<?php

namespace App\Enums;

enum WorkflowStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case OnHold = 'on_hold';
}
