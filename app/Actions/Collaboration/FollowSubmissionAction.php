<?php

namespace App\Actions\Collaboration;

use App\Exceptions\CollaborationException;
use App\Models\Submission;
use App\Models\SubmissionWatcher;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class FollowSubmissionAction
{
    /**
     * @param  bool|null  $notifyComments  null keeps the follower's current choice (new followers: on)
     * @param  bool|null  $notifyStatus  null keeps the follower's current choice (new followers: on)
     */
    public function execute(
        Submission $submission,
        User $actor,
        User $target,
        ?bool $notifyComments = null,
        ?bool $notifyStatus = null,
        bool $fromMention = false,
    ): SubmissionWatcher {
        if ($target->isSystemOwner() && ! $actor->is($target)) {
            throw CollaborationException::cannotWatch('that account is not available');
        }

        if (! $target->is_active) {
            throw CollaborationException::cannotWatch('that account is inactive');
        }

        if ($actor->is($target)) {
            if (! $actor->can('follow', $submission)) {
                throw CollaborationException::cannotWatch('you cannot follow this request');
            }
        } elseif ($fromMention) {
            if (! $actor->can('comment', $submission)) {
                throw CollaborationException::cannotWatch('you cannot mention people on this request');
            }

            // A mention may only open the request to someone new if the author could
            // add them as a follower anyway (RV-SEC-09).
            if (! $target->can('view', $submission) && ! $actor->can('addWatcher', $submission)) {
                throw CollaborationException::cannotMention($target->name);
            }
        } elseif (! $actor->can('addWatcher', $submission)) {
            throw CollaborationException::cannotWatch('you cannot add followers to this request');
        }

        return DB::transaction(function () use ($submission, $actor, $target, $notifyComments, $notifyStatus): SubmissionWatcher {
            $watcher = SubmissionWatcher::query()->firstOrNew([
                'submission_id' => $submission->id,
                'user_id' => $target->id,
            ]);

            if (! $watcher->exists) {
                $watcher->added_by_id = $actor->id;
            }

            $watcher->notify_comments = $notifyComments ?? $watcher->notify_comments ?? true;
            $watcher->notify_status = $notifyStatus ?? $watcher->notify_status ?? true;
            $watcher->save();

            return $watcher->load(['user', 'addedBy']);
        });
    }
}
