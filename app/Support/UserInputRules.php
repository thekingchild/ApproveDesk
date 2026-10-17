<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Rules\AssignableUser;
use App\Rules\E164PhoneNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Validation for the Users form, in one place (RV-ARC-08). The importer checks
 * the same things per row with its own messages, using the same helpers
 * (E164Phone, User::assignable()).
 */
final class UserInputRules
{
    /**
     * @param  list<mixed>  $passwordRules  only used when creating
     * @return array<string, list<mixed>>
     */
    public static function form(?User $target, array $passwordRules = []): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($target)],
            'phone' => ['nullable', 'string', 'max:20', new E164PhoneNumber, Rule::unique(User::class, 'phone')->ignore($target)],
            'departmentId' => ['nullable', 'integer', Rule::exists(Department::class, 'id')],
            'managerId' => ['nullable', 'integer', Rule::notIn([$target?->id]), new AssignableUser],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', Rule::exists(Role::class, 'name')],
            'isActive' => ['required', 'boolean'],
        ];

        if ($target === null) {
            $rules['password'] = $passwordRules;
        }

        return $rules;
    }

    /** @return list<string|ValidationRule> */
    public static function rejectionReason(): array
    {
        return ['required', 'string', 'max:1000'];
    }
}
