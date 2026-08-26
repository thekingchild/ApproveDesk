<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

final class GrantableRoleService
{
    /** @return list<string> */
    public function for(User $actor): array
    {
        $query = Role::query()->where('name', '!=', 'super-admin');

        if (! $actor->isSystemOwner()) {
            $query->where('name', '!=', 'admin');
        }

        return $query->orderBy('name')->pluck('name')->all();
    }

    /** @param list<string> $roles */
    public function assertCanAssign(User $actor, array $roles): void
    {
        if (array_diff($roles, $this->for($actor)) !== []) {
            throw ValidationException::withMessages([
                'roles' => __('You cannot grant one or more of the selected roles.'),
            ]);
        }
    }

    /** @param list<string> $roles */
    public function assertCanSync(User $actor, User $target, array $roles): void
    {
        $this->assertCanAssign($actor, $roles);

        if (! $actor->isSystemOwner() && $target->hasRole('admin')) {
            throw ValidationException::withMessages([
                'roles' => __('Only the System owner may change the administrator role.'),
            ]);
        }
    }
}
