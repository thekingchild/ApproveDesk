<?php

namespace Tests\Feature\Console;

use App\Models\SystemOwner;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignSystemOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_command_fills_missing_owner_and_converts_other_super_admins(): void
    {
        $target = User::factory()->create(['email' => 'owner@example.test', 'is_active' => true]);
        $legacy = User::factory()->create()->assignRole(['super-admin', 'approver']);

        $this->artisan('owner:assign', ['email' => 'owner@example.test'])->assertSuccessful();

        $this->assertDatabaseHas('system_owners', ['id' => SystemOwner::SINGLETON_ID, 'user_id' => $target->id]);
        $this->assertTrue($target->fresh()->hasRole('super-admin'));
        $this->assertFalse($legacy->fresh()->hasRole('super-admin'));
        $this->assertTrue($legacy->fresh()->hasAllRoles(['admin', 'approver']));
    }
}
