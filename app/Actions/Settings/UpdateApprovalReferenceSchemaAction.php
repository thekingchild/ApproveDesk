<?php

namespace App\Actions\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateApprovalReferenceSchemaAction
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function execute(User $actor, array $input): array
    {
        if (! $actor->isSystemOwner()) {
            throw new AuthorizationException('Only the System owner can change the approval ID format.');
        }

        $validated = Validator::make($input, [
            'prefix' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'separator' => ['present', 'string', Rule::in(['', '-', '/'])],
            'year_pattern' => ['required', Rule::in(['none', 'yy', 'yyyy'])],
            'month_pattern' => ['required', Rule::in(['none', 'mm', 'mmm'])],
            'digit_length' => ['required', 'integer', 'between:4,12'],
            'generation_mode' => ['required', Rule::in(['sequential', 'random'])],
        ])->validate();

        $validated['prefix'] = strtoupper($validated['prefix']);

        DB::transaction(function () use ($validated): void {
            foreach ($validated as $name => $value) {
                Setting::put('approvals.reference.'.$name, (string) $value);
            }
        });

        return $validated;
    }
}
