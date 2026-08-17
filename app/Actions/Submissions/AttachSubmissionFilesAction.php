<?php

namespace App\Actions\Submissions;

use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

/**
 * Stores uploaded attachments on the private local disk (outside the
 * webroot) and records their metadata. Idempotent per upload instance —
 * call once per newly added file batch.
 */
final class AttachSubmissionFilesAction
{
    /**
     * @param  array<int, UploadedFile>  $files
     * @return array<int, SubmissionFile>
     */
    public function execute(Submission $submission, User $uploader, array $files): array
    {
        Validator::make(
            ['files' => $files],
            ['files.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx,csv,txt']],
        )->validate();

        return collect($files)->map(function (UploadedFile $file) use ($submission, $uploader): SubmissionFile {
            return SubmissionFile::create([
                'submission_id' => $submission->id,
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $file->store("submissions/{$submission->id}", 'local'),
                'disk' => 'local',
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $uploader->id,
            ]);
        })->all();
    }
}
