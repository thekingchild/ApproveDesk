<?php

namespace App\Exceptions;

class CollaborationException extends DomainException
{
    public static function cannotMention(string $name): self
    {
        return new self(__('You cannot mention :name: they cannot open this request and you cannot add followers. Ask the requester or an approver to add them.', ['name' => $name]));
    }

    public static function cannotWatch(string $reason): self
    {
        return new self(__('You cannot follow this request: :reason.', ['reason' => $reason]));
    }

    public static function cannotUnfollow(string $reason): self
    {
        return new self(__('You cannot unfollow this request: :reason.', ['reason' => $reason]));
    }

    public static function cannotNudge(string $reason): self
    {
        return new self(__('Reminder not sent: :reason.', ['reason' => $reason]));
    }

    public static function commentNotEditable(string $reason): self
    {
        return new self(__('This comment cannot be edited: :reason.', ['reason' => $reason]));
    }

    public static function outOfOfficeConflict(string $reason): self
    {
        return new self(__('Out-of-office schedule conflict: :reason.', ['reason' => $reason]));
    }

    public static function invalidReply(): self
    {
        return new self(__('Replies must belong to a top-level comment on the same request.'));
    }
}
