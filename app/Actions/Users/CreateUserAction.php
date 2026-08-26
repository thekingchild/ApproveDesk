<?php

namespace App\Actions\Users;

use App\DTOs\UserData;
use App\Models\User;
use App\Services\Users\ActiveUserLimitService;
use App\Services\Users\GrantableRoleService;
use Illuminate\Support\Facades\Gate;

final class CreateUserAction
{
    public function __construct(
        private readonly GrantableRoleService $roles,
        private readonly ActiveUserLimitService $limits,
    ) {}

    public function execute(UserData $data, User $actor): User
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $this->roles->assertCanAssign($actor, $data->roles);

        return $this->limits->runActivation($data->isActive ? 1 : 0, function () use ($data): User {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'phone' => $data->phone,
                'password' => $data->password,
                'department_id' => $data->departmentId,
                'manager_id' => $data->managerId,
                'is_active' => $data->isActive,
                'email_verified_at' => now(),
            ]);

            $user->syncRoles($data->roles);

            return $user->load(['department', 'roles']);
        });
    }
}
