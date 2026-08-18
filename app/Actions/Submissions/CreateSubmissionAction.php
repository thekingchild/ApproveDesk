<?php

namespace App\Actions\Submissions;

use App\DTOs\SubmissionData;
use App\Enums\RequestTypeVersionStatus;
use App\Enums\SubmissionStatus;
use App\Models\RequestTypeVersion;
use App\Models\Submission;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Creates a submission record (as draft or pending review). File
 * attachments are handled separately by AttachSubmissionFilesAction.
 */
final class CreateSubmissionAction
{
    public function __construct(
        private readonly SyncLegacySubmissionFieldValuesAction $syncLegacyFieldValues,
    ) {}

    public function execute(SubmissionData $data, User $submitter, bool $asDraft): Submission
    {
        return DB::transaction(function () use ($data, $submitter, $asDraft): Submission {
            $requestTypeVersion = $data->requestTypeVersionId === null
                ? RequestTypeVersion::general()
                : RequestTypeVersion::query()->with('requestType')->findOrFail($data->requestTypeVersionId);

            if ($requestTypeVersion->status !== RequestTypeVersionStatus::Published || ! $requestTypeVersion->requestType->is_active) {
                throw new DomainException('Submissions require an active, published request type version.');
            }

            $submission = Submission::create([
                ...$data->toAttributes(),
                'request_type_version_id' => $requestTypeVersion->id,
                'submitter_id' => $submitter->id,
                'department_id' => $data->departmentId ?? $submitter->department_id,
                'status' => $asDraft ? SubmissionStatus::Draft : SubmissionStatus::Pending,
            ]);

            $this->syncLegacyFieldValues->execute($submission);

            return $submission;
        });
    }
}
