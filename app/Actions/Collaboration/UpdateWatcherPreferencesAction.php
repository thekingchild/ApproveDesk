<?php

namespace App\Actions\Collaboration;

use App\Exceptions\CollaborationException;
use App\Models\Submission;
use App\Models\SubmissionWatcher;
use App\Models\User;

final class UpdateWatcherPreferencesAction
{
    public function execute(
        Submission $submission,
        User $actor,
        bool $notifyComments,
        bool $notifyStatus,
    ): SubmissionWatcher {
        $watcher = SubmissionWatcher::query()
            ->where('submission_id', $submission->id)
            ->where('user_id', $actor->id)
            ->first();

        if ($watcher === null) {
            throw CollaborationException::cannotWatch('you are not following this request');
        }

        $watcher->update([
            'notify_comments' => $notifyComments,
            'notify_status' => $notifyStatus,
        ]);

        return $watcher->fresh() ?? $watcher;
    }
}
