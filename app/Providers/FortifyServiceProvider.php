<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Support\RateLimitProfiles;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureAuthentication();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        // Phase UO.2: route self-registrations through the OTP gate when the
        // activation mode requires email confirmation.
        app()->singleton(RegisterResponse::class, \App\Http\Responses\RegisterResponse::class);
        app()->singleton(LoginResponse::class, \App\Http\Responses\LoginResponse::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    private function configureAuthentication(): void
    {
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->string(Fortify::username()))->first();

            return $user !== null
                && $user->is_active
                && Hash::check((string) $request->input('password'), $user->password)
                    ? $user
                    : null;
        });
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return RateLimitProfiles::limit('login', (string) $request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return RateLimitProfiles::limit('login', $throttleKey);
        });

        RateLimiter::for('passkeys', function (Request $request) {
            $credentialId = $request->input('credential.id');

            return RateLimitProfiles::limit(
                'login',
                ($credentialId ?: $request->session()->getId()).'|'.$request->ip(),
            );
        });

        // Phase UO.2: OTP verification attempts and resend requests.
        RateLimiter::for('registration-otp', fn (Request $request) => Limit::perMinute(10)->by(Str::transliterate(Str::lower((string) $request->session()->get('registration.confirm.email'))).'|'.$request->ip()));

        RateLimiter::for('registration-resend', fn (Request $request) => Limit::perMinute(3)->by(Str::transliterate(Str::lower((string) $request->session()->get('registration.confirm.email'))).'|'.$request->ip()));
    }
}
