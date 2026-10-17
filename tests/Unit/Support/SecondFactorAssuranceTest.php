<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Services\Auth\SecondFactorAssurance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SecondFactorAssuranceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mark_records_user_bound_assurance_for_the_current_session(): void
    {
        Carbon::setTestNow('2026-08-26 10:15:30');
        $user = User::factory()->create();
        $request = $this->requestWithSession();
        $assurance = new SecondFactorAssurance;

        $assurance->mark($request, $user, 'totp');

        $this->assertSame([
            'user_id' => (string) $user->getAuthIdentifier(),
            'verified_at' => now()->timestamp,
            'method' => 'totp',
        ], $request->session()->get(SecondFactorAssurance::SESSION_KEY));

        Carbon::setTestNow('2036-08-26 10:15:30');
        $this->assertTrue($assurance->satisfied($request, $user));
        $this->assertFalse($assurance->satisfied($this->requestWithSession(), $user));
    }

    public function test_clear_removes_assurance_from_the_session(): void
    {
        $user = User::factory()->create();
        $request = $this->requestWithSession();
        $assurance = new SecondFactorAssurance;
        $assurance->mark($request, $user, 'recovery_code');

        $assurance->clear($request);

        $this->assertFalse($request->session()->has(SecondFactorAssurance::SESSION_KEY));
        $this->assertFalse($assurance->satisfied($request, $user));
    }

    public function test_mark_rejects_unrecognized_methods(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SecondFactorAssurance)->mark(
            $this->requestWithSession(),
            User::factory()->create(),
            'password',
        );
    }

    public function test_malformed_or_wrong_user_markers_do_not_satisfy_assurance(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $request = $this->requestWithSession();
        $assurance = new SecondFactorAssurance;
        $validMarker = [
            'user_id' => (string) $user->getAuthIdentifier(),
            'verified_at' => now()->timestamp,
            'method' => 'totp',
        ];

        $malformedMarkers = [
            [...$validMarker, 'method' => 'password'],
            [...$validMarker, 'user_id' => $user->getAuthIdentifier()],
            [...$validMarker, 'verified_at' => (string) now()->timestamp],
            [...$validMarker, 'extra' => true],
            ['user_id' => $validMarker['user_id']],
            'not-an-array',
            null,
        ];

        foreach ($malformedMarkers as $marker) {
            $request->session()->put(SecondFactorAssurance::SESSION_KEY, $marker);
            $this->assertFalse($assurance->satisfied($request, $user));
        }

        $request->session()->put(SecondFactorAssurance::SESSION_KEY, $validMarker);
        $this->assertFalse($assurance->satisfied($request, $otherUser));
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
