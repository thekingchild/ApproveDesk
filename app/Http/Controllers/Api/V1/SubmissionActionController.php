<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WorkflowActionType;
use App\Exceptions\WorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProcessWorkflowActionRequest;
use App\Http\Resources\Api\V1\SubmissionResource;
use App\Models\Submission;
use App\Services\WorkflowEngine;
use Illuminate\Validation\ValidationException;

class SubmissionActionController extends Controller
{
    public function __invoke(
        ProcessWorkflowActionRequest $request,
        Submission $submission,
        WorkflowEngine $engine,
    ): SubmissionResource {
        try {
            $engine->processAction(
                $submission->workflowInstance()->firstOrFail(),
                $request->user(),
                WorkflowActionType::from($request->string('action')->toString()),
                $request->string('comment')->trim()->toString() ?: null,
                expectedStepId: $request->filled('step_id') ? $request->integer('step_id') : null,
            );
        } catch (WorkflowException $exception) {
            throw ValidationException::withMessages(['action' => [$exception->getMessage()]]);
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
