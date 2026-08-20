<?php

namespace App\Actions\Collaboration;

use App\Exceptions\CollaborationException;
use App\Models\Submission;
use App\Models\SubmissionWatcher;
use App\Models\User;

final class UnfollowSubmissionAction
{
    public function execute(Submission $submission, User $actor, User $target): void
    {
        $watcher = SubmissionWatcher::query()
            ->where('submission_id', $submission->id)
            ->where('user_id', $target->id)
            ->first();

        if ($watcher === null) {
            throw CollaborationException::cannotUnfollow('you are not following this request');
        }

        $mayRemove = $actor->is($target)
            || $watcher->added_by_id === $actor->id
            || $actor->can('addWatcher', $submission);

        if (! $mayRemove) {
            throw CollaborationException::cannotUnfollow('only you can remove your own follow');
        }

        $watcher->delete();
    }
}
