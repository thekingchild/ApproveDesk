<?php

namespace App\Actions\Fortify;

use App\Actions\Security\VerifyTurnstileAction;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Branding;
use App\Support\RegistrationSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        app(VerifyTurnstileAction::class)->execute();

        if (! Branding::registrationOpen()) {
            throw ValidationException::withMessages([
                'email' => __('Registration is currently disabled.'),
            ]);
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        // Domain allow-list (UO.3): enforced before any account exists. The
        // message never enumerates the allowed domains.
        if (! RegistrationSettings::domainAllowed((string) $input['email'])) {
            throw ValidationException::withMessages([
                'email' => __('Please register with your organization email address.'),
            ]);
        }

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'is_active' => true,
            // OTP mode (UO.2) leaves the address unverified; the confirmation
            // screen gates sign-in. Immediate mode verifies at creation.
            'email_verified_at' => RegistrationSettings::requiresOtp() ? null : now(),
        ]);

        return $user;
    }
}
