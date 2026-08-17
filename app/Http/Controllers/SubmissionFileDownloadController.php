<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves submission attachments from the private disk. Access is authorized
 * by the time-limited signed URL itself (2h), so email links work without a
 * session; routes are protected by the `signed` middleware.
 */
class SubmissionFileDownloadController extends Controller
{
    public function __invoke(Request $request, Submission $submission, SubmissionFile $file): StreamedResponse
    {
        abort_unless($file->submission_id === $submission->id, 404);

        abort_unless(Storage::disk($file->disk)->exists($file->stored_path), 404);

        return Storage::disk($file->disk)->download($file->stored_path, $file->original_name);
    }
}
