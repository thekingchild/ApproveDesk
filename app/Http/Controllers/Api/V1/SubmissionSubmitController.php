<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Collaboration\PostCommentAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Exceptions\WorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubmitSubmissionRequest;
use App\Http\Resources\Api\V1\SubmissionResource;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmissionSubmitController extends Controller
{
    public function __invoke(SubmitSubmissionRequest $request, Submission $submission, SubmitSubmissionAction $submit, PostCommentAction $comments): SubmissionResource
    {
        $comment = $request->string('comment')->trim()->toString();

        try {
            DB::transaction(function () use ($submit, $submission, $request, $comment, $comments): void {
                if ($comment !== '') {
                    $comments->execute($submission, $request->user(), $comment);
                }

                $submit->execute($submission);
            });
        } catch (WorkflowException $exception) {
            throw ValidationException::withMessages(['submit' => [$exception->userMessage()]]);
        }

        return new SubmissionResource($submission->fresh([
            'submitter:id,name',
            'department:id,name',
            'workflowInstance.currentStep',
            'workflowInstance.actions.actor:id,name',
            'workflowInstance.actions.step:id,name',
        ]));
    }
}
