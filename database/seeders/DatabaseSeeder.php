<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Production-safe base seed: roles, permissions, and default settings.
     * Demo data only materializes in local/testing environments.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            SettingsSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
