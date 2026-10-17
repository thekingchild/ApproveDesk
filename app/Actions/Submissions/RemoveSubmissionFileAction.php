<?php

namespace App\Actions\Submissions;

use App\Enums\SubmissionFileRevisionAction;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\SubmissionFileRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Removes an attachment from a request the requester is editing.
 *
 * A draft that was never submitted loses the file outright. Once a request
 * has been submitted, approvers may have relied on the file, so it is only
 * withdrawn from the current attachments: the stored copy stays, the record
 * notes who removed it, and evidence packs still include it (RV-DATA-06).
 * Every removal is recorded in the append-only file history. Nothing can be
 * removed while the request is on legal hold.
 */
final class RemoveSubmissionFileAction
{
    public function execute(Submission $submission, SubmissionFile $file, User $actor): void
    {
        abort_unless($file->submission_id === $submission->id, 404);
        Gate::forUser($actor)->authorize('update', $submission);

        if ($submission->isOnLegalHold()) {
            throw ValidationException::withMessages([
                'attachments' => __('This request is on legal hold, so its attachments cannot be removed.'),
            ]);
        }

        $disk = Storage::disk($file->disk);
        $contents = $disk->exists($file->stored_path) ? $disk->get($file->stored_path) : null;
        $submitted = $submission->workflowInstance()->exists();

        DB::transaction(function () use ($submission, $file, $actor, $contents, $submitted): void {
            SubmissionFileRevision::query()->create([
                'submission_id' => $submission->id,
                'submission_file_id' => $file->id,
                'actor_id' => $actor->id,
                'action' => SubmissionFileRevisionAction::Removed,
                'original_name' => $file->original_name,
                'sha256' => is_string($contents) ? hash('sha256', $contents) : null,
                'size_bytes' => $file->size_bytes,
            ]);

            if ($submitted) {
                $file->forceFill(['removed_by' => $actor->id])->save();
                $file->delete();

                return;
            }

            $file->forceDelete();
        });

        if (! $submitted) {
            $disk->delete($file->stored_path);
        }
    }
}
