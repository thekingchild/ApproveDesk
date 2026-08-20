<?php

namespace App\Actions\Collaboration;

use App\Exceptions\CollaborationException;
use App\Models\Comment;
use App\Models\CommentRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class EditCommentAction
{
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

        return DB::transaction(function () use ($comment, $editor, $body): Comment {
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

            return $comment->fresh(['author', 'revisions.editor', 'mentions.user']) ?? $comment;
        });
    }
}
