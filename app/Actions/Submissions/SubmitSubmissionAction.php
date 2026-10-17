<?php

namespace App\Actions\Submissions;

use App\Enums\SubmissionStatus;
use App\Events\SubmissionCreated;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\SubmissionFieldValue;
use App\Services\RequestTypes\RequestSchemaCompiler;
use App\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * Sends a draft or returned submission into review. A draft starts its
 * workflow instance for the first time; a returned submission resumes the
 * instance that was put on hold, preserving its place in the chain.
 */
final class SubmitSubmissionAction
{
    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly RequestSchemaCompiler $schema,
    ) {}

    public function execute(Submission $submission): Submission
    {
        return DB::transaction(function () use ($submission): Submission {
            $this->assertRequiredFieldsComplete($submission);

            $instance = $submission->workflowInstance()->first();

            if ($instance === null) {
                $submission->update(['status' => SubmissionStatus::Pending]);
                $instance = $this->engine->initialize($submission);
                DB::afterCommit(fn () => event(new SubmissionCreated($submission, $instance)));
            } else {
                activity()->performedOn($submission)->causedBy($submission->submitter)->event('submission-revised')->log('Submitter revised and resubmitted the request');
                $this->engine->resume($instance);
            }

            return $submission;
        });
    }

    /**
     * Drafts may be saved incomplete; submitting may not, whichever path calls
     * this (RV-BUG-33). Built-in types keep their own Form Request validation.
     */
    private function assertRequiredFieldsComplete(Submission $submission): void
    {
        $version = $submission->requestTypeVersion()->with(['requestType', 'fields'])->first();
        if ($version === null || $version->requestType->is_system) {
            return;
        }

        $values = $submission->fieldValues()->with('field')->get()
            ->mapWithKeys(fn (SubmissionFieldValue $value): array => [$value->field->key => $value->resolvedValue()])
            ->all();

        $missing = $this->schema->missingRequired($version, $values);
        if ($missing !== []) {
            throw WorkflowException::missingRequiredFields($missing);
        }
    }
}
