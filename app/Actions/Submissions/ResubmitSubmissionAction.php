<?php

namespace App\Actions\Submissions;

use App\Actions\Collaboration\PostCommentAction;
use App\Enums\SubmissionStatus;
use App\Exceptions\WorkflowException;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Send a draft, returned or awaiting-information request (back) for approval,
 * with the requester's answer posted as a comment. The request page and the
 * API both use this, so they apply the same rule (RV-ARC-02): an answer is
 * required while information is requested, and the answer and the submit land
 * together or not at all (RV-BUG-07).
 */
final class ResubmitSubmissionAction
{
    public function __construct(
        private readonly PostCommentAction $comments,
        private readonly SubmitSubmissionAction $submit,
    ) {}

    /**
     * @throws ValidationException when an information request has no answer
     * @throws WorkflowException when the request cannot enter its workflow
     */
    public function execute(Submission $submission, User $actor, ?string $answer = null): Submission
    {
        Gate::forUser($actor)->authorize('submit', $submission);

        $answer = trim((string) $answer);
        if ($answer === '' && $submission->status === SubmissionStatus::AwaitingInformation) {
            throw ValidationException::withMessages(['comment' => __('Answer the information request before sending it back.')]);
        }

        return DB::transaction(function () use ($submission, $actor, $answer): Submission {
            if ($answer !== '') {
                $this->comments->execute($submission, $actor, $answer);
            }

            $this->submit->execute($submission);

            return $submission;
        });
    }
}
