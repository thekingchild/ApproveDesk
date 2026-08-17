<?php

namespace App\Actions\Users;

use App\DTOs\UserData;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateUserAction
{
    public function execute(UserData $data, User $actor): User
    {
        $this->guardSuperAdminAssignment($data, $actor);

        return DB::transaction(function () use ($data): User {
            $user = User::create([
                'name' => $data->name,
                'email' => $data->email,
                'password' => $data->password,
                'department_id' => $data->departmentId,
                'is_active' => $data->isActive,
                'email_verified_at' => now(),
            ]);

            $user->syncRoles($data->roles);

            return $user->load(['department', 'roles']);
        });
    }

    private function guardSuperAdminAssignment(UserData $data, User $actor): void
    {
        if (in_array('super-admin', $data->roles, true) && ! $actor->hasRole('super-admin')) {
            throw ValidationException::withMessages([
                'roles' => __('Only a super administrator may grant that role.'),
            ]);
        }
    }
}
