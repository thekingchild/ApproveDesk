<?php

namespace App\Actions\Collaboration;

use App\Exceptions\CollaborationException;
use App\Models\Comment;
use App\Models\CommentMention;
use App\Models\CommentRevision;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\MentionedInCommentNotification;
use App\Services\Collaboration\MentionParser;
use Illuminate\Support\Facades\DB;

final class EditCommentAction
{
    public function __construct(
        private readonly MentionParser $mentions,
        private readonly FollowSubmissionAction $follow,
    ) {}

    /**
     * People newly mentioned by the edit are mentioned, notified and made
     * followers exactly as when a comment is posted; people already mentioned
     * are not notified again (RV-BUG-38).
     */
    public function execute(Comment $comment, User $editor, string $body): Comment
    {
        $body = trim($body);

        if (! $comment->isEditableBy($editor)) {
            $reason = $comment->author_id !== $editor->id
                ? 'only the author can edit it'
                : 'the '.Comment::EDIT_WINDOW_MINUTES.'-minute edit window has closed';

            throw CollaborationException::commentNotEditable($reason);
        }

        if ($body === '') {
            throw CollaborationException::commentNotEditable('the body cannot be empty');
        }

        $added = [];
        $submission = null;

        $edited = DB::transaction(function () use ($comment, $editor, $body, &$added, &$submission): Comment {
            $comment = Comment::query()->whereKey($comment->id)->lockForUpdate()->firstOrFail();

            if (! $comment->isEditableBy($editor)) {
                throw CollaborationException::commentNotEditable('the '.Comment::EDIT_WINDOW_MINUTES.'-minute edit window has closed');
            }

            CommentRevision::query()->create([
                'comment_id' => $comment->id,
                'editor_id' => $editor->id,
                'body' => $comment->body,
            ]);

            $comment->update([
                'body' => $body,
                'edited_at' => now(),
            ]);

            $submission = $comment->commentable_type === Submission::class
                ? Submission::query()->find($comment->commentable_id)
                : null;
            if ($submission instanceof Submission) {
                $already = $comment->mentions()->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
                $added = array_values(array_diff($this->mentions->userIds($body), $already, [$editor->id]));

                foreach ($added as $userId) {
                    CommentMention::query()->create(['comment_id' => $comment->id, 'user_id' => $userId]);

                    $mentioned = User::query()->find($userId);
                    if ($mentioned instanceof User) {
                        $this->follow->execute($submission, $editor, $mentioned, fromMention: true);
                    }
                }
            }

            return $comment->fresh(['author', 'revisions.editor', 'mentions.user']) ?? $comment;
        });

        if ($added !== [] && $submission instanceof Submission) {
            User::query()->whereIn('id', $added)->each(
                fn (User $user) => $user->notify(new MentionedInCommentNotification($submission, $edited)),
            );
        }

        return $edited;
    }
}
