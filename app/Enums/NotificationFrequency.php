<?php

namespace App\Enums;

enum NotificationFrequency: string
{
    case Immediate = 'immediate';
    case Daily = 'daily';
    case Weekly = 'weekly';
    case InAppOnly = 'in_app_only';
}
