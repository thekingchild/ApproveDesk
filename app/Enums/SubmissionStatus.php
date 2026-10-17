<?php

namespace App\Enums;

enum SubmissionStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case InReview = 'in_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Returned = 'returned';
    case AwaitingInformation = 'awaiting_information';
    case Withdrawn = 'withdrawn';

    /** The name people see; matches docs/kb/statuses.md. */
    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Pending => __('Pending'),
            self::InReview => __('In review'),
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Returned => __('Returned'),
            self::AwaitingInformation => __('Awaiting information'),
            self::Withdrawn => __('Withdrawn'),
        };
    }

    /** Flux badge colour; the one map every list and page uses (RV-ARC-15). */
    public function color(): string
    {
        return match ($this) {
            self::Draft, self::Withdrawn => 'zinc',
            self::Pending, self::InReview => 'blue',
            self::Approved => 'lime',
            self::Rejected => 'red',
            self::Returned, self::AwaitingInformation => 'amber',
        };
    }

    /** True while the requester has to do something. */
    public function needsRequester(): bool
    {
        return $this === self::Returned || $this === self::AwaitingInformation;
    }
}
