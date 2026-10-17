<?php

namespace App\Actions\Submissions;

use App\Enums\SubmissionFileRevisionAction;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionFileRevision;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            $stored = $file->store("submissions/{$submission->id}", 'local');
            $contents = Storage::disk('local')->get($stored);
            $record = SubmissionFile::create([
                'submission_id' => $submission->id,
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $stored,
                'disk' => 'local',
                'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
                'uploaded_by' => $uploader->id,
            ]);

            SubmissionFileRevision::query()->create([
                'submission_id' => $submission->id,
                'submission_file_id' => $record->id,
                'actor_id' => $uploader->id,
                'action' => SubmissionFileRevisionAction::Added,
                'original_name' => $record->original_name,
                'sha256' => is_string($contents) ? hash('sha256', $contents) : null,
                'size_bytes' => $record->size_bytes,
            ]);

            return $record;
        })->all();
    }
}
