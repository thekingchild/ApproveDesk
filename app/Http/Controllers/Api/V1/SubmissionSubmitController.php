<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Submissions\SubmitSubmissionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SubmitSubmissionRequest;
use App\Http\Resources\Api\V1\SubmissionResource;
use App\Models\Submission;

class SubmissionSubmitController extends Controller
{
    public function __invoke(SubmitSubmissionRequest $request, Submission $submission, SubmitSubmissionAction $submit): SubmissionResource
    {
        $submit->execute($submission);

        return new SubmissionResource($submission->fresh([
            'submitter:id,name',
            'department:id,name',
            'workflowInstance.currentStep',
            'workflowInstance.actions.actor:id,name',
            'workflowInstance.actions.step:id,name',
        ]));
    }
}
