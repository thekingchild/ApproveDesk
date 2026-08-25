<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

final class PermissionCache
{
    public static function forgetIfReady(): void
    {
        try {
            if (! Schema::hasTable('cache')) {
                return;
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } catch (Throwable) {
            // package:discover and first-run boot happen before migrate.
        }
    }
}
