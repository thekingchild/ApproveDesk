<?php

namespace App\Actions\Submissions;

use App\DTOs\SubmissionData;
use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates a submission record (as draft or pending review). File
 * attachments are handled separately by AttachSubmissionFilesAction.
 */
final class CreateSubmissionAction
{
    public function execute(SubmissionData $data, User $submitter, bool $asDraft): Submission
    {
        return DB::transaction(function () use ($data, $submitter, $asDraft): Submission {
            return Submission::create([
                ...$data->toAttributes(),
                'submitter_id' => $submitter->id,
                'department_id' => $data->departmentId ?? $submitter->department_id,
                'status' => $asDraft ? SubmissionStatus::Draft : SubmissionStatus::Pending,
            ]);
        });
    }
}
