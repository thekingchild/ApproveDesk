<?php

namespace App\Http\Controllers;

use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Services\Privacy\FileWatermarker;
use App\Support\SecuritySettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves submission attachments from the private disk. Routes require both
 * an authenticated, authorized participant and a time-limited signature.
 */
class SubmissionFileDownloadController extends Controller
{
    public function __invoke(Request $request, Submission $submission, SubmissionFile $file): Response
    {
        abort_unless($file->submission_id === $submission->id, 404);

        Gate::authorize('downloadFiles', $submission);

        abort_unless(Storage::disk($file->disk)->exists($file->stored_path), 404);

        if ($request->boolean('preview')) {
            abort_unless($file->isPreviewable(), 415);

            return Storage::disk($file->disk)->response(
                $file->stored_path,
                $file->original_name,
                ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff'],
                'inline',
            );
        }

        if (! SecuritySettings::watermarkDownloads()) {
            return Storage::disk($file->disk)->download($file->stored_path, $file->original_name);
        }

        $contents = (string) Storage::disk($file->disk)->get($file->stored_path);
        $notice = app(FileWatermarker::class)->notice(
            $request->user()?->name.' <'.$request->user()?->email.'>',
            (string) $submission->reference_number,
        );
        $watermarked = app(FileWatermarker::class)->apply(
            $contents,
            $file->original_name,
            $file->mime_type,
            $notice,
        );

        return response($watermarked['contents'], 200, [
            'Content-Type' => $watermarked['mime'],
            'Content-Disposition' => 'attachment; filename="'.$watermarked['name'].'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
