<?php

namespace App\Actions\Users;

use App\DTOs\UserData;
use App\Models\User;
use App\Services\Roles\RoleAssignmentService;
use App\Services\Users\ActiveUserLimitService;
use App\Services\Users\GrantableRoleService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateUserAction
{
    public function __construct(
        private readonly GrantableRoleService $roles,
        private readonly RoleAssignmentService $assignments,
        private readonly ActiveUserLimitService $limits,
    ) {}

    public function execute(User $user, UserData $data, User $actor): User
    {
        if ($user->isSystemOwner()) {
            throw ValidationException::withMessages(['user' => __('The System owner cannot be changed from user administration.')]);
        }
        Gate::forUser($actor)->authorize('update', $user);
        if ($data->isActive !== $user->is_active) {
            Gate::forUser($actor)->authorize('activate', $user);
        }
        $held = $user->roles()->pluck('name')->all();
        $grantable = $this->roles->for($actor);
        $requested = array_values(array_intersect($data->roles, $grantable));
        $preserved = array_values(array_diff($held, $grantable));
        $roles = array_values(array_unique([...$preserved, ...$requested]));
        $this->roles->assertCanSync($actor, $user, $requested);
        $needsSlot = $data->isActive && ! $user->is_active;

        return $this->limits->runActivation($needsSlot ? 1 : 0, function () use ($user, $data, $actor, $roles): User {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ($user->is($actor) && ! $data->isActive) {
                throw ValidationException::withMessages(['isActive' => __('You cannot deactivate your own account.')]);
            }
            $user->update([
                'name' => $data->name,
                'email' => $data->email,
                'phone' => $data->phone,
                'department_id' => $data->departmentId,
                'manager_id' => $data->managerId,
                'is_active' => $data->isActive,
            ]);
            $this->assignments->sync($actor, $user, $roles);

            return $user->load(['department', 'roles']);
        });
    }
}
