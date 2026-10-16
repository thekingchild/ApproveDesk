<?php

namespace App\Actions\Users;

use App\DTOs\UserData;
use App\Models\User;
use App\Services\Roles\RoleAssignmentService;
use App\Services\Users\ActiveUserLimitService;
use App\Services\Users\GrantableRoleService;
use Illuminate\Auth\Access\AuthorizationException;
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
        if (! $actor->isSystemOwner() && $user->hasRole('admin')) {
            throw ValidationException::withMessages(['roles' => __('Only the System owner may change the administrator role.')]);
        }
        Gate::forUser($actor)->authorize('update', $user);
        if ($data->isActive !== $user->is_active) {
            Gate::forUser($actor)->authorize('activate', $user);
        }
        $needsSlot = $data->isActive && ! $user->is_active;

        return $this->limits->runActivation($needsSlot ? 1 : 0, function () use ($user, $data, $actor): User {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $held = collect($user->roles()->pluck('name')->all())->map(fn ($role): string => (string) $role)->unique()->sort()->values()->all();
            $requested = collect($data->roles)->map(fn ($role): string => (string) $role)->unique()->sort()->values()->all();
            $rolesChanged = $requested !== $held;
            $roles = $held;

            if ($rolesChanged) {
                try {
                    Gate::forUser($actor)->authorize('assignRoles', $user);
                } catch (AuthorizationException) {
                    throw ValidationException::withMessages(['roles' => __('You cannot change these roles.')]);
                }
                $grantable = $this->roles->for($actor);
                $forged = array_diff($requested, $grantable, $held);
                if ($forged !== []) {
                    throw ValidationException::withMessages(['roles' => __('You cannot grant one or more of the selected roles.')]);
                }
                $roles = array_values(array_unique([
                    ...array_diff($held, $grantable),
                    ...array_intersect($requested, $grantable),
                ]));
                sort($roles);
            }

            if ($user->is($actor) && ! $data->isActive) {
                throw ValidationException::withMessages(['isActive' => __('You cannot deactivate your own account.')]);
            }
            if (strcasecmp((string) $user->email, $data->email) !== 0
                && ! $user->is($actor)
                && ! Gate::forUser($actor)->allows('manageIdentity', $user)) {
                throw ValidationException::withMessages(['email' => __('Only the System owner can change the email address of someone with more access than you.')]);
            }
            $user->update([
                'name' => $data->name,
                'email' => $data->email,
                'phone' => $data->phone,
                'department_id' => $data->departmentId,
                'manager_id' => $data->managerId,
                'is_active' => $data->isActive,
            ]);
            if ($rolesChanged) {
                $this->assignments->sync($actor, $user, $roles);
            }

            return $user->load(['department', 'roles']);
        });
    }
}
