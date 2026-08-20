<?php

namespace App\Actions\Collaboration;

use App\Exceptions\CollaborationException;
use App\Models\Comment;
use App\Models\CommentMention;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\CommentPostedNotification;
use App\Notifications\MentionedInCommentNotification;
use App\Services\Collaboration\MentionParser;
use Illuminate\Support\Facades\DB;

final class PostCommentAction
{
    public function __construct(
        private readonly MentionParser $mentions,
        private readonly FollowSubmissionAction $follow,
    ) {}

    /**
     * @param  array<int, int>  $mentionedUserIds
     */
    public function execute(
        Submission $submission,
        User $author,
        string $body,
        ?int $parentId = null,
        array $mentionedUserIds = [],
    ): Comment {
        if (! $author->can('comment', $submission)) {
            throw CollaborationException::cannotWatch('you cannot comment on this request');
        }

        $body = trim($body);
        $parent = $this->resolveParent($submission, $parentId);
        $mentionIds = $this->mentions->userIds($body, $mentionedUserIds);
        $mentionIds = array_values(array_filter($mentionIds, fn (int $id): bool => $id !== $author->id));

        $comment = DB::transaction(function () use ($submission, $author, $body, $parent, $mentionIds): Comment {
            $comment = Comment::create([
                'commentable_id' => $submission->id,
                'commentable_type' => Submission::class,
                'author_id' => $author->id,
                'parent_id' => $parent?->id,
                'body' => $body,
            ]);

            foreach ($mentionIds as $userId) {
                CommentMention::query()->create([
                    'comment_id' => $comment->id,
                    'user_id' => $userId,
                ]);

                $mentioned = User::query()->find($userId);
                if ($mentioned instanceof User) {
                    $this->follow->execute($submission, $author, $mentioned, fromMention: true);
                }
            }

            return $comment->load(['author', 'mentions.user', 'replies']);
        });

        $this->notify($submission, $author, $comment, $mentionIds);

        return $comment;
    }

    private function resolveParent(Submission $submission, ?int $parentId): ?Comment
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Comment::query()->find($parentId);
        if ($parent === null
            || $parent->commentable_id !== $submission->id
            || $parent->commentable_type !== Submission::class
            || $parent->parent_id !== null) {
            throw CollaborationException::invalidReply();
        }

        return $parent;
    }

    /**
     * @param  list<int>  $mentionIds
     */
    private function notify(Submission $submission, User $author, Comment $comment, array $mentionIds): void
    {
        User::query()->whereIn('id', $mentionIds)->each(function (User $user) use ($submission, $comment): void {
            $user->notify(new MentionedInCommentNotification($submission, $comment));
        });

        $submission->watchers()
            ->with('user')
            ->where('notify_comments', true)
            ->where('user_id', '!=', $author->id)
            ->whereNotIn('user_id', $mentionIds)
            ->get()
            ->pluck('user')
            ->filter()
            ->each(fn (User $user) => $user->notify(new CommentPostedNotification($submission, $comment)));
    }
}
