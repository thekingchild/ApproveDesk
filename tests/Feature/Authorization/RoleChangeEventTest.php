<?php

namespace Tests\Feature\Authorization;

use App\Models\RoleChangeEvent;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleChangeEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_change_events_are_append_only(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $actor = User::factory()->create();
        $role = Role::findByName('approver');
        $event = RoleChangeEvent::query()->create([
            'actor_id' => $actor->id,
            'role_id' => $role->id,
            'action' => 'permissions_changed',
            'before' => ['permissions' => ['approvals.act']],
            'after' => ['permissions' => []],
        ]);

        $this->expectException(LogicException::class);
        $event->update(['before' => ['tampered' => true]]);
    }

    public function test_role_change_events_cannot_be_deleted(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $event = RoleChangeEvent::query()->create([
            'actor_id' => User::factory()->create()->id,
            'action' => 'role_created',
        ]);

        $this->expectException(LogicException::class);
        $event->delete();
    }
}
