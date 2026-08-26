<?php

namespace App\Actions\Users;

use App\DTOs\UserData;
use App\Models\User;
use App\Services\Users\ActiveUserLimitService;
use App\Services\Users\GrantableRoleService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateUserAction
{
    public function __construct(
        private readonly GrantableRoleService $roles,
        private readonly ActiveUserLimitService $limits,
    ) {}

    public function execute(User $user, UserData $data, User $actor): User
    {
        if ($user->isSystemOwner()) {
            throw ValidationException::withMessages([
                'user' => __('The System owner cannot be changed from user administration.'),
            ]);
        }

        Gate::forUser($actor)->authorize('update', $user);

        $needsSlot = $data->isActive && ! $user->is_active;

        return $this->limits->runActivation($needsSlot ? 1 : 0, function () use ($user, $data, $actor): User {
            /** @var User $user */
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->roles->assertCanSync($actor, $user, $data->roles);
            $this->guardChanges($user, $data, $actor);

            $user->update([
                'name' => $data->name,
                'email' => $data->email,
                'phone' => $data->phone,
                'department_id' => $data->departmentId,
                'manager_id' => $data->managerId,
                'is_active' => $data->isActive,
            ]);
            $user->syncRoles($data->roles);

            return $user->load(['department', 'roles']);
        });
    }

    private function guardChanges(User $user, UserData $data, User $actor): void
    {
        if ($user->is($actor) && ! $data->isActive) {
            throw ValidationException::withMessages([
                'isActive' => __('You cannot deactivate your own account.'),
            ]);
        }

    }
}
