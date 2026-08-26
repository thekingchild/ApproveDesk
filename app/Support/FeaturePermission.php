<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class FeaturePermission
{
    public static function allows(User $user, string $permission): bool
    {
        if ($user->isSystemOwner()) {
            return true;
        }

        try {
            if ($user->hasPermissionTo($permission)) {
                return true;
            }
        } catch (PermissionDoesNotExist) {
            // A pending migration may not have created the new permission yet.
        }

        $entry = PermissionCatalogue::find($permission);
        foreach ($entry['legacy'] ?? [] as $legacy) {
            try {
                if ($user->hasPermissionTo($legacy)) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                continue;
            }
        }

        return false;
    }
}
