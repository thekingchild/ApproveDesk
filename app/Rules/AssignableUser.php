<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A user who may be picked as someone's line manager or assignee (the System owner is not). */
final class AssignableUser implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_numeric($value) || ! User::query()->assignable()->whereKey((int) $value)->exists()) {
            $fail(__('Choose an available line manager.'));
        }
    }
}
