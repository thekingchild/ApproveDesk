<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\Submission;
use App\Models\User;

class CommentPolicy
{
    public function update(User $user, Comment $comment): bool
    {
        if ($comment->commentable_type === Submission::class && $comment->commentable instanceof Submission) {
            if (! $user->can('comment', $comment->commentable)) {
                return false;
            }
        }

        return $comment->isEditableBy($user);
    }
}
