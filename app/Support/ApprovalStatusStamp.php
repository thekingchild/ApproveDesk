<?php

namespace App\Support;

use App\Enums\SubmissionStatus;
use RuntimeException;

final class ApprovalStatusStamp
{
    /** @var array<string, string> */
    private const FILES = [
        'draft' => 'draft.png',
        'pending' => 'pending.png',
        'in_review' => 'in-review.png',
        'approved' => 'approved.png',
        'rejected' => 'rejected.png',
        'returned' => 'returned.png',
        'awaiting_information' => 'awaiting-information.png',
        'withdrawn' => 'withdrawn.png',
    ];

    public function key(SubmissionStatus $status): string
    {
        return $status->value;
    }

    public function dataUri(SubmissionStatus $status): string
    {
        $path = public_path('images/approval-status-stamps/'.self::FILES[$status->value]);
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('The approval status stamp asset is missing.');
        }

        return 'data:image/png;base64,'.base64_encode($contents);
    }
}
