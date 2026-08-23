<?php

namespace Tests\Unit\Services\Auth;

use App\Mail\RegistrationOtpMail;
use App\Models\RegistrationOtpCode;
use App\Models\User;
use App\Services\Auth\RegistrationOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RegistrationOtpFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_stores_hashed_code_and_throttles_immediate_resend(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = app(RegistrationOtpService::class);

        $service->issue($user);

        $record = RegistrationOtpCode::query()->where('email', $user->email)->sole();
        $this->assertNotSame('', (string) $record->code_hash);
        $this->assertTrue($record->expires_at->isFuture());

        Mail::assertSent(RegistrationOtpMail::class);

        $this->expectException(\RuntimeException::class);
        $service->issue($user);
    }

    public function test_expired_code_is_rejected_with_clear_error(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = app(RegistrationOtpService::class);
        $service->issue($user);

        RegistrationOtpCode::query()
            ->where('email', $user->email)
            ->update(['expires_at' => now()->subMinute()]);

        [$ok, $error] = $service->verify($user, '123456');
        $this->assertFalse($ok);
        $this->assertStringContainsString('expired', (string) $error);
    }

    public function test_wrong_code_fails_but_correct_code_verifies_and_consumes(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email_verified_at' => null]);
        $service = app(RegistrationOtpService::class);

        RegistrationOtpCode::create([
            'email' => $user->email,
            'code_hash' => Hash::make('654321'),
            'expires_at' => now()->addMinutes(10),
        ]);

        [$ok] = $service->verify($user, '111111');
        $this->assertFalse($ok);
        $this->assertNull($user->refresh()->email_verified_at);

        [$ok, $error] = $service->verify($user, '654321');
        $this->assertTrue($ok, (string) $error);
        $this->assertNotNull($user->refresh()->email_verified_at);

        // The code is single-use.
        [$okAgain] = $service->verify($user, '654321');
        $this->assertFalse($okAgain);
    }
}
