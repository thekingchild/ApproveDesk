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
}
