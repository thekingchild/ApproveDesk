<?php

namespace App\Enums;

enum SubmissionType: string
{
    case Document = 'document';
    case Note = 'note';
    case Memo = 'memo';
    case Request = 'request';
}
