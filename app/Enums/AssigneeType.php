<?php

namespace App\Enums;

enum AssigneeType: string
{
    case User = 'user';
    case Role = 'role';
    case Department = 'department';
    case RequesterManager = 'requester_manager';
    case DepartmentManager = 'department_manager';
    case FormField = 'form_field';
    case External = 'external';

    public function requiresExplicitAssignees(): bool
    {
        return in_array($this, [self::User, self::Role, self::Department], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::User => 'Named users',
            self::Role => 'Role',
            self::Department => 'Department members',
            self::RequesterManager => "Requester's manager",
            self::DepartmentManager => 'Department manager',
            self::FormField => 'User selected on the form',
            self::External => 'External resolver',
        };
    }
}
