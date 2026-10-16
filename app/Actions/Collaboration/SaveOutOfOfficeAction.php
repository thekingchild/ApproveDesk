<?php

namespace App\Actions\Collaboration;

use App\Exceptions\CollaborationException;
use App\Models\OutOfOffice;
use App\Models\User;
use App\Services\Collaboration\EndOutOfOfficeDelegations;
use App\Services\Collaboration\OutOfOfficeFallback;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class SaveOutOfOfficeAction
{
    public function __construct(private readonly OutOfOfficeFallback $fallbacks) {}

    public function execute(
        User $user,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?int $fallbackUserId,
        ?string $message = null,
        ?int $existingId = null,
    ): OutOfOffice {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw CollaborationException::outOfOfficeConflict('the end must be after the start');
        }

        if ($endsAt->lessThanOrEqualTo(now()) && $existingId === null) {
            throw CollaborationException::outOfOfficeConflict('the end must be in the future');
        }

        $fallback = null;
        if ($fallbackUserId !== null) {
            $fallback = User::query()->assignable()->whereKey($fallbackUserId)->where('is_active', true)->first();
            if ($fallback === null || $fallback->is($user)) {
                throw CollaborationException::outOfOfficeConflict('choose a different active fallback approver');
            }
        }

        $overlap = OutOfOffice::query()
            ->where('user_id', $user->id)
            ->overlapping($startsAt, $endsAt)
            ->when($existingId !== null, fn ($query) => $query->whereKeyNot($existingId))
            ->exists();

        if ($overlap) {
            throw CollaborationException::outOfOfficeConflict('this period overlaps another of your out-of-office schedules');
        }

        if ($fallback !== null) {
            $fallbackBusy = OutOfOffice::query()
                ->where('user_id', $fallback->id)
                ->overlapping($startsAt, $endsAt)
                ->get();

            if ($fallbackBusy->isNotEmpty()) {
                if ($fallbackBusy->contains('fallback_user_id', $user->id)) {
                    throw CollaborationException::outOfOfficeConflict($fallback->name.' already falls back to you during this period');
                }

                throw CollaborationException::outOfOfficeConflict($fallback->name.' is also out of office during this period');
            }
        }

        $schedule = DB::transaction(function () use ($user, $startsAt, $endsAt, $fallback, $message, $existingId): OutOfOffice {
            $schedule = $existingId !== null
                ? OutOfOffice::query()->where('user_id', $user->id)->whereKey($existingId)->firstOrFail()
                : new OutOfOffice(['user_id' => $user->id]);

            // Changing the window or fallback hands back steps delegated under the old
            // one; current fallbacks are re-applied below for the new window (RV-ENG-12).
            if ($schedule->exists && (! $schedule->ends_at->equalTo($endsAt) || $schedule->fallback_user_id !== $fallback?->id)) {
                app(EndOutOfOfficeDelegations::class)->execute($schedule, $user);
            }

            $schedule->fill([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'fallback_user_id' => $fallback?->id,
                'message' => ($message !== null && trim($message) !== '') ? trim($message) : null,
            ]);
            $schedule->save();

            return $schedule->load('fallback');
        });

        if ($schedule->covers(now()) && $schedule->fallback_user_id !== null) {
            $this->fallbacks->applyActiveSchedules();
        }

        return $schedule;
    }
}
