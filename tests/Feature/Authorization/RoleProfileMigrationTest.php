<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\RoleProfile;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleProfileMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_profiles_built_ins_and_preserves_external_roles(): void
    {
        $external = Role::findOrCreate('finance-reviewer');

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseHas('roles', ['id' => $external->id, 'name' => 'finance-reviewer']);
        $this->assertDatabaseHas('role_profiles', [
            'role_id' => $external->id,
            'slug' => 'finance-reviewer',
            'is_builtin' => false,
            'is_protected' => false,
        ]);
        $this->assertDatabaseHas('role_profiles', [
            'role_id' => Role::findByName('admin')->id,
            'is_builtin' => true,
            'is_protected' => true,
        ]);
        $this->assertSame(Role::query()->count(), RoleProfile::query()->count());
    }
}
