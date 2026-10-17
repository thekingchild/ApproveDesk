<?php

namespace App\Enums;

enum RoleChangeAction: string
{
    case RoleCreated = 'role_created';
    case RoleCloned = 'role_cloned';
    case PermissionsChanged = 'permissions_changed';
    case RoleArchived = 'role_archived';
    case RoleRestored = 'role_restored';
    case RoleAssigned = 'role_assigned';
    case RoleRemoved = 'role_removed';
}
