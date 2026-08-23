<?php

namespace App\Services\Auth;

use App\Mail\RegistrationOtpMail;
use App\Models\RegistrationOtpCode;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * 6-digit email OTP for self-registration confirmation (Phase UO.2).
 * Codes are stored hashed, expire in 10 minutes, allow max 5 verification
 * attempts, and resend is throttled (60s between sends, 5 per hour).
 */
final class RegistrationOtpService
{
    private const TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const RESEND_SECONDS = 60;

    private const HOURLY_RESEND_CAP = 5;

    /**
     * Issue a fresh code for the address, invalidating previous ones.
     *
     * @throws RuntimeException when resend is throttled or mail fails
     */
    public function issue(User $user): void
    {
        if ($this->resendThrottledUntil($user->email)->isFuture()) {
            throw new RuntimeException(__('Please wait a minute before requesting another code.'));
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($user, $code): void {
            RegistrationOtpCode::query()
                ->where('email', $user->email)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            RegistrationOtpCode::create([
                'email' => $user->email,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);
        });

        Mail::to($user->email)->send(new RegistrationOtpMail($code, self::TTL_MINUTES));
    }

    /** Whether this address currently has an unconsumed, unexpired confirmation code. */
    public function hasPending(string $email): bool
    {
        return RegistrationOtpCode::query()
            ->where('email', $email)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /** Seconds until the next send is permitted (0 = allowed now). */
    public function resendThrottledUntil(string $email): CarbonImmutable|Carbon
    {
        $latest = RegistrationOtpCode::query()
            ->where('email', $email)
            ->orderByDesc('created_at')
            ->first();

        if ($latest === null) {
            return now()->subSecond();
        }

        $hourAgo = now()->subHour();
        $sentLastHour = RegistrationOtpCode::query()
            ->where('email', $email)
            ->where('created_at', '>=', $hourAgo)
            ->count();

        if ($sentLastHour >= self::HOURLY_RESEND_CAP) {
            return $latest->created_at->addHour();
        }

        return $latest->created_at->addSeconds(self::RESEND_SECONDS);
    }

    /**
     * Attempt confirmation; returns true and marks the user verified on success.
     * Failure reasons surface as the second return value.
     *
     * @return array{bool, ?string}
     */
    public function verify(User $user, string $code): array
    {
        $record = RegistrationOtpCode::query()
            ->where('email', $user->email)
            ->whereNull('consumed_at')
            ->orderByDesc('created_at')
            ->first();

        if ($record === null || $record->expires_at->isPast()) {
            return [false, __('That code has expired. Request a new one.')];
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            return [false, __('Too many attempts. Request a new code.')];
        }

        $record->increment('attempts');

        if (! Hash::check(trim($code), (string) $record->code_hash)) {
            return [false, __('That code is not correct.')];
        }

        DB::transaction(function () use ($record, $user): void {
            $record->forceFill(['consumed_at' => now()])->save();
            $user->forceFill(['email_verified_at' => now()])->save();

            // Any remaining live codes for this address are dead once verified.
            RegistrationOtpCode::query()
                ->where('email', $user->email)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
        });

        return [true, null];
    }
}
