<?php

namespace Tests\Feature\Admin;

use App\Actions\Privacy\AnonymizeUserAction;
use App\Actions\Users\CreateUserAction;
use App\Actions\Users\UpdateUserAction;
use App\DTOs\UserData;
use App\Models\SystemOwner;
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\Users\UserImportService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SystemOwnerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_gate_bypass_belongs_to_owner_not_role_only_super_admin(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        $roleOnly = User::factory()->create()->assignRole('super-admin');

        $this->assertTrue(Gate::forUser($owner)->allows('owner-only-unregistered-ability'));
        $this->assertFalse(Gate::forUser($roleOnly)->allows('owner-only-unregistered-ability'));
    }

    public function test_user_policy_never_allows_admin_editing_of_the_owner(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        $admin = User::factory()->create()->assignRole('admin');
        $roleOnlySuperAdmin = User::factory()->create()->assignRole('super-admin');
        $roleOnlySuperAdmin->givePermissionTo('users.manage');
        $policy = app(UserPolicy::class);

        $this->assertFalse($policy->update($admin, $owner));
        $this->assertFalse($policy->update($roleOnlySuperAdmin, $owner));
        $this->assertFalse($policy->anonymize($admin, $owner));
        $this->assertFalse($policy->update($owner, $owner));
        $this->assertFalse($policy->anonymize($owner, $owner));
    }

    public function test_direct_create_action_requires_user_management_permission(): void
    {
        $actor = User::factory()->create()->assignRole('submitter');

        $this->expectException(AuthorizationException::class);

        app(CreateUserAction::class)->execute(new UserData(
            name: 'Forged User',
            email: 'forged@example.test',
            phone: null,
            departmentId: null,
            roles: ['approver'],
            isActive: true,
            password: 'Secure-password-123',
        ), $actor);
    }

    public function test_direct_update_action_requires_user_management_permission(): void
    {
        $actor = User::factory()->create()->assignRole('submitter');
        $target = User::factory()->create()->assignRole('approver');

        $this->expectException(AuthorizationException::class);

        app(UpdateUserAction::class)->execute($target, new UserData(
            name: 'Forged Update',
            email: $target->email,
            phone: null,
            departmentId: null,
            roles: ['approver'],
            isActive: true,
        ), $actor);
    }

    public function test_owner_cannot_update_the_owner_through_admin_action(): void
    {
        $owner = User::factory()->create(['name' => 'Original Owner'])->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        try {
            app(UpdateUserAction::class)->execute($owner, new UserData(
                name: 'Mutated Owner',
                email: $owner->email,
                phone: null,
                departmentId: null,
                roles: ['admin'],
                isActive: false,
            ), $owner);
            $this->fail('Expected owner mutation to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }

        $this->assertSame('Original Owner', $owner->fresh()->name);
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertTrue($owner->fresh()->hasRole('super-admin'));
    }

    public function test_delegated_admin_cannot_change_an_admin_through_direct_action(): void
    {
        $actor = User::factory()->create()->assignRole('admin');
        $target = User::factory()->create()->assignRole('admin');

        $this->expectException(ValidationException::class);

        app(UpdateUserAction::class)->execute($target, new UserData(
            name: 'Changed Admin',
            email: $target->email,
            phone: null,
            departmentId: null,
            roles: ['admin'],
            isActive: true,
        ), $actor);
    }

    public function test_delegated_admin_cannot_create_another_admin_through_direct_action(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $this->expectException(ValidationException::class);

        app(CreateUserAction::class)->execute(new UserData(
            name: 'Escalated',
            email: 'escalated@example.test',
            phone: null,
            departmentId: null,
            roles: ['admin'],
            isActive: true,
            password: 'Secure-password-123',
        ), $admin);
    }

    public function test_forged_generic_update_cannot_change_an_existing_password(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);
        $target = User::factory()->create(['password' => 'original-password'])->assignRole('approver');

        app(UpdateUserAction::class)->execute($target, new UserData(
            name: $target->name,
            email: $target->email,
            phone: null,
            departmentId: null,
            roles: ['approver'],
            isActive: true,
            password: 'forged-new-password',
        ), $owner);

        $this->assertTrue(Hash::check('original-password', $target->fresh()->password));
        $this->assertFalse(Hash::check('forged-new-password', $target->fresh()->password));
    }

    public function test_owner_cannot_be_anonymized_through_direct_action(): void
    {
        $owner = User::factory()->create()->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        try {
            app(AnonymizeUserAction::class)->execute($owner, User::factory()->create()->assignRole('admin'));
            $this->fail('Expected owner anonymization to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('user', $exception->errors());
        }

        $this->assertFalse($owner->fresh()->isAnonymized());
        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_import_update_cannot_overwrite_the_owner(): void
    {
        $owner = User::factory()->create(['name' => 'Protected Owner', 'email' => 'owner@example.test'])->assignRole('super-admin');
        SystemOwner::query()->create(['id' => SystemOwner::SINGLETON_ID, 'user_id' => $owner->id]);

        $result = app(UserImportService::class)->commit([[
            'status' => 'duplicate',
            'name' => 'Overwritten Owner',
            'email' => $owner->email,
            'department' => '',
            'manager_email' => '',
            'phone' => '',
            'roles' => 'admin',
        ]], $owner, 'update');

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame('Protected Owner', $owner->fresh()->name);
        $this->assertTrue($owner->fresh()->hasRole('super-admin'));
    }

    public function test_delegated_admin_cannot_import_an_administrator(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        $report = app(UserImportService::class)->analyze([[
            'name' => 'Escalated Admin',
            'email' => 'escalated-import@example.test',
            'department' => '',
            'manager_email' => '',
            'phone' => '',
            'roles' => 'admin',
        ]], $admin);

        $this->assertSame('error', $report[0]['status']);
    }
}
