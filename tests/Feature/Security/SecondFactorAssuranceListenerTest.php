<?php

namespace Tests\Feature\Security;

use App\Listeners\MarkSecondFactorAssurance;
use App\Models\User;
use App\Services\Auth\SecondFactorAssurance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Passkeys\Events\PasskeyRegistered;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkey;
use Tests\TestCase;

class SecondFactorAssuranceListenerTest extends TestCase
{
    use RefreshDatabase;

    public function test_second_factor_events_are_registered_with_the_assurance_listener(): void
    {
        foreach ([
            ValidTwoFactorAuthenticationCodeProvided::class,
            TwoFactorAuthenticationConfirmed::class,
            PasskeyVerified::class,
            PasskeyRegistered::class,
        ] as $event) {
            $this->assertTrue(Event::hasListeners($event), "No assurance listener registered for {$event}.");
        }
    }

    public function test_valid_totp_event_marks_before_guard_login_and_survives_session_regeneration(): void
    {
        $user = User::factory()->create();
        $request = $this->requestWithSession();
        $request->setUserResolver(static fn (): null => null);
        $request->session()->forget('login.id');
        $assurance = new SecondFactorAssurance;
        $listener = new MarkSecondFactorAssurance($request, $assurance);

        $listener->handle(new ValidTwoFactorAuthenticationCodeProvided($user));

        $this->assertTrue($assurance->satisfied($request, $user));
        $this->assertSame('totp', $request->session()->get(SecondFactorAssurance::SESSION_KEY)['method']);

        $request->session()->regenerate();
        $this->assertTrue($assurance->satisfied($request, $user));
    }

    public function test_recovery_code_event_is_recorded_as_recovery_code(): void
    {
        $user = User::factory()->create();
        $request = $this->requestWithSession();
        $request->merge(['recovery_code' => 'valid-recovery-code']);
        $request->setUserResolver(static fn (): null => null);
        $listener = new MarkSecondFactorAssurance($request, new SecondFactorAssurance);

        $listener->handle(new ValidTwoFactorAuthenticationCodeProvided($user));

        $this->assertSame('recovery_code', $request->session()->get(SecondFactorAssurance::SESSION_KEY)['method']);
    }

    public function test_verified_passkey_marks_before_guard_login(): void
    {
        $user = User::factory()->create();
        $request = $this->requestWithSession();
        $request->setUserResolver(static fn (): null => null);
        $listener = new MarkSecondFactorAssurance($request, new SecondFactorAssurance);

        $listener->handle(new PasskeyVerified($user, new Passkey));

        $this->assertSame([
            'user_id' => (string) $user->getAuthIdentifier(),
            'verified_at' => now()->timestamp,
            'method' => 'passkey',
        ], $request->session()->get(SecondFactorAssurance::SESSION_KEY));
    }

    public function test_confirmed_totp_enrollment_marks_matching_authenticated_user(): void
    {
        $user = User::factory()->create();
        $request = $this->requestWithSession();
        $request->setUserResolver(static fn (): User => $user);
        $listener = new MarkSecondFactorAssurance($request, new SecondFactorAssurance);

        $listener->handle(new TwoFactorAuthenticationConfirmed($user));

        $this->assertSame('enrollment', $request->session()->get(SecondFactorAssurance::SESSION_KEY)['method']);
    }

    public function test_registered_passkey_marks_matching_authenticated_user(): void
    {
        $user = User::factory()->create();
        $request = $this->requestWithSession();
        $request->setUserResolver(static fn (): User => $user);
        $listener = new MarkSecondFactorAssurance($request, new SecondFactorAssurance);

        $listener->handle(new PasskeyRegistered($user, new Passkey));

        $this->assertSame('enrollment', $request->session()->get(SecondFactorAssurance::SESSION_KEY)['method']);
    }

    public function test_enrollment_event_requires_matching_authenticated_user(): void
    {
        $user = User::factory()->create();
        $request = $this->requestWithSession();
        $request->setUserResolver(static fn (): null => null);
        $listener = new MarkSecondFactorAssurance($request, new SecondFactorAssurance);

        $listener->handle(new TwoFactorAuthenticationConfirmed($user));

        $this->assertFalse($request->session()->has(SecondFactorAssurance::SESSION_KEY));
    }

    public function test_mismatched_authenticated_user_does_not_receive_assurance(): void
    {
        $eventUser = User::factory()->create();
        $authenticatedUser = User::factory()->create();
        $request = $this->requestWithSession();
        $request->setUserResolver(static fn (): User => $authenticatedUser);
        $listener = new MarkSecondFactorAssurance($request, new SecondFactorAssurance);

        $listener->handle(new ValidTwoFactorAuthenticationCodeProvided($eventUser));

        $this->assertFalse($request->session()->has(SecondFactorAssurance::SESSION_KEY));
    }

    private function requestWithSession(): Request
    {
        $request = Request::create('/');
        $session = new Store('testing', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        return $request;
    }
}
