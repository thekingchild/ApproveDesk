<?php

namespace App\Support;

use App\Models\SubmissionFile;
use Illuminate\Support\Facades\URL;

/**
 * Signed links to a request's files. The download controller re-checks access;
 * the signature and lifetime only stop a copied link from working for long.
 */
final class SubmissionFileLinks
{
    public const DOWNLOAD_MINUTES = 120;

    public const PREVIEW_MINUTES = 30;

    public static function download(SubmissionFile $file): string
    {
        return URL::temporarySignedRoute(
            'submissions.files.download',
            now()->addMinutes(self::DOWNLOAD_MINUTES),
            ['submission' => $file->submission_id, 'file' => $file->id],
        );
    }

    /** An inline link for the preview dialog. */
    public static function preview(SubmissionFile $file): string
    {
        return URL::temporarySignedRoute(
            'submissions.files.download',
            now()->addMinutes(self::PREVIEW_MINUTES),
            ['submission' => $file->submission_id, 'file' => $file->id, 'preview' => 1],
        );
    }
}
