<?php

namespace App\Actions\Collaboration;

use App\Exceptions\CollaborationException;
use App\Models\OutOfOffice;
use App\Models\User;

final class DeleteOutOfOfficeAction
{
    public function execute(User $user, int $scheduleId): void
    {
        $schedule = OutOfOffice::query()
            ->where('user_id', $user->id)
            ->whereKey($scheduleId)
            ->first();

        if ($schedule === null) {
            throw CollaborationException::outOfOfficeConflict('that schedule was not found');
        }

        $schedule->delete();
    }
}
