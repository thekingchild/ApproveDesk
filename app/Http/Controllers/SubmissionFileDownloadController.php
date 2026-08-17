<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use App\Models\SubmissionFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves submission attachments from the private disk. Routes require both
 * an authenticated, authorized participant and a time-limited signature.
 */
class SubmissionFileDownloadController extends Controller
{
    public function __invoke(Request $request, Submission $submission, SubmissionFile $file): StreamedResponse
    {
        abort_unless($file->submission_id === $submission->id, 404);

        Gate::authorize('downloadFiles', $submission);

        abort_unless(Storage::disk($file->disk)->exists($file->stored_path), 404);

        return Storage::disk($file->disk)->download($file->stored_path, $file->original_name);
    }
}
