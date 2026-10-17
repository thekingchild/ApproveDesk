<?php

namespace App\Enums;

/**
 * The built-in roles code may name (ADR 0008, ADR 0009). Only Policies, Gates
 * and the RBAC services compare against them; screens ask for an ability.
 */
enum SystemRole: string
{
    /** Marks the System owner; holds no permissions of its own. */
    case SuperAdmin = 'super-admin';
    case Admin = 'admin';
    case Auditor = 'auditor';
}
