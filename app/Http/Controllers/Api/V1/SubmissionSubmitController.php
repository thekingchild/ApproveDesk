<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Submissions\ResubmitSubmissionAction;
use App\Exceptions\WorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubmitSubmissionRequest;
use App\Http\Resources\Api\V1\SubmissionResource;
use App\Models\Submission;
use Illuminate\Validation\ValidationException;

class SubmissionSubmitController extends Controller
{
    public function __invoke(SubmitSubmissionRequest $request, Submission $submission, ResubmitSubmissionAction $resubmit): SubmissionResource
    {
        try {
            $resubmit->execute($submission, $request->user(), $request->string('comment')->toString());
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
