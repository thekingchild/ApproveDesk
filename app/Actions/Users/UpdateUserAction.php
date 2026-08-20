<?php

namespace App\Actions\Users;

use App\DTOs\UserData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateUserAction
{
    public function execute(User $user, UserData $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            /** @var User $user */
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $this->guardChanges($user, $data, $actor);

            $attributes = [
                'name' => $data->name,
                'email' => $data->email,
                'phone' => $data->phone,
                'department_id' => $data->departmentId,
                'manager_id' => $data->managerId,
                'is_active' => $data->isActive,
            ];

            if ($data->password !== null) {
                $attributes['password'] = $data->password;
            }

            $user->update($attributes);
            $user->syncRoles($data->roles);

            return $user->load(['department', 'roles']);
        });
    }

    private function guardChanges(User $user, UserData $data, User $actor): void
    {
        $assigningSuperAdmin = in_array('super-admin', $data->roles, true);

        if ($assigningSuperAdmin && ! $actor->hasRole('super-admin')) {
            throw ValidationException::withMessages([
                'roles' => __('Only a super administrator may grant that role.'),
            ]);
        }

        if ($user->is($actor) && ! $data->isActive) {
            throw ValidationException::withMessages([
                'isActive' => __('You cannot deactivate your own account.'),
            ]);
        }

        if ($user->hasRole('super-admin') && (! $assigningSuperAdmin || ! $data->isActive)) {
            $otherActiveSuperAdmins = User::query()
                ->whereKeyNot($user)
                ->where('is_active', true)
                ->role('super-admin')
                ->exists();

            if (! $otherActiveSuperAdmins) {
                throw ValidationException::withMessages([
                    'roles' => __('The final active super administrator cannot be demoted or deactivated.'),
                ]);
            }
        }
    }
}
