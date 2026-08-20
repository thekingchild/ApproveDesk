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
     * @param  array{notify_comments?: bool, notify_status?: bool}  $preferences
     */
    public function execute(
        Submission $submission,
        User $actor,
        User $target,
        array $preferences = [],
        bool $fromMention = false,
    ): SubmissionWatcher {
        if (! $target->is_active) {
            throw CollaborationException::cannotWatch('that account is inactive');
        }

        if ($actor->is($target)) {
            if (! $actor->can('view', $submission) && ! $submission->isWatchedBy($actor)) {
                throw CollaborationException::cannotWatch('you do not have access to this request');
            }
        } elseif ($fromMention) {
            if (! $actor->can('comment', $submission)) {
                throw CollaborationException::cannotWatch('you cannot mention people on this request');
            }
        } elseif (! $actor->can('addWatcher', $submission)) {
            throw CollaborationException::cannotWatch('you cannot add followers to this request');
        }

        return DB::transaction(function () use ($submission, $actor, $target, $preferences): SubmissionWatcher {
            $watcher = SubmissionWatcher::query()->firstOrNew([
                'submission_id' => $submission->id,
                'user_id' => $target->id,
            ]);

            if (! $watcher->exists) {
                $watcher->added_by_id = $actor->id;
            }

            $watcher->notify_comments = $preferences['notify_comments'] ?? $watcher->notify_comments ?? true;
            $watcher->notify_status = $preferences['notify_status'] ?? $watcher->notify_status ?? true;
            $watcher->save();

            return $watcher->load(['user', 'addedBy']);
        });
    }
}
