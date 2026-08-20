<?php

namespace App\Exceptions;

use RuntimeException;

class CollaborationException extends RuntimeException
{
    public static function cannotWatch(string $reason): self
    {
        return new self("You cannot follow this request: {$reason}.");
    }

    public static function cannotUnfollow(string $reason): self
    {
        return new self("You cannot unfollow this request: {$reason}.");
    }

    public static function cannotNudge(string $reason): self
    {
        return new self("Reminder not sent: {$reason}.");
    }

    public static function commentNotEditable(string $reason): self
    {
        return new self("This comment cannot be edited: {$reason}.");
    }

    public static function outOfOfficeConflict(string $reason): self
    {
        return new self("Out-of-office schedule conflict: {$reason}.");
    }

    public static function invalidReply(): self
    {
        return new self('Replies must belong to a top-level comment on the same request.');
    }
}
