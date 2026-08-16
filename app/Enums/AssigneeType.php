<?php

namespace App\Enums;

enum AssigneeType: string
{
    case User = 'user';
    case Role = 'role';
    case Department = 'department';
}
