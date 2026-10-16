<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Submissions\CreateSubmissionAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\DTOs\SubmissionData;
use App\Exceptions\WorkflowException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexSubmissionRequest;
use App\Http\Requests\Api\V1\ShowSubmissionRequest;
use App\Http\Requests\Api\V1\StoreSubmissionRequest;
use App\Http\Resources\Api\V1\SubmissionResource;
use App\Models\Submission;
use App\Services\Workflow\AssignedStepConstraint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmissionController extends Controller
{
    public function index(IndexSubmissionRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $validated = $request->validated();
        $query = Submission::query()
            ->with(['submitter:id,name', 'department:id,name', 'requestTypeVersion.requestType:id,name,slug', 'workflowInstance.currentStep'])
            ->when(! $user->can('submissions.view.all'), function ($builder) use ($user): void {
                $builder->where(function ($builder) use ($user): void {
                    $builder->where('submitter_id', $user->id)
                        ->orWhereHas('workflowInstance', function ($instances) use ($user): void {
                            app(AssignedStepConstraint::class)->constrainInstances($instances, $user);
                        })
                        ->orWhereHas('workflowInstance.delegations', fn ($builder) => $builder
                            ->where('starts_at', '<=', now())
                            ->where('expires_at', '>', now())
                            ->where('delegate_id', $user->id)
                            ->whereColumn('delegations.step_id', 'workflow_instances.current_step_id'))
                        ->orWhereHas('workflowInstance.actions', fn ($builder) => $builder->where('actor_id', $user->id));
                });
            })
            ->when(isset($validated['status']), fn ($builder) => $builder->where('status', $validated['status']))
            ->when(isset($validated['type']), fn ($builder) => $builder->where('type', $validated['type']));

        $perPage = $validated['per_page'] ?? 25;

        if (isset($validated['cursor']) || $request->query->has('cursor')) {
            return SubmissionResource::collection(
                $query->orderByDesc('updated_at')->orderByDesc('id')->cursorPaginate($perPage)->withQueryString()
            );
        }

        return SubmissionResource::collection($query->latest('updated_at')->paginate($perPage)->withQueryString());
    }

    public function store(
        StoreSubmissionRequest $request,
        CreateSubmissionAction $createSubmission,
        SubmitSubmissionAction $submitSubmission,
    ): JsonResponse {
        $validated = $request->validated();
        $submit = (bool) ($validated['submit'] ?? false);

        // Create and submit together: a refused submit must not leave a stranded
        // Pending request with no workflow behind it (RV-BUG-07).
        try {
            $submission = DB::transaction(function () use ($createSubmission, $submitSubmission, $validated, $request, $submit): Submission {
                $submission = $createSubmission->execute(SubmissionData::fromValidated($validated), $request->user(), ! $submit);

                return $submit ? $submitSubmission->execute($submission) : $submission;
            });
        } catch (WorkflowException $exception) {
            throw ValidationException::withMessages(['submit' => [$exception->getMessage()]]);
        }

        return (new SubmissionResource($submission->load($this->relations())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ShowSubmissionRequest $request, Submission $submission): SubmissionResource
    {
        return new SubmissionResource($submission->load($this->relations(withActions: true)));
    }

    /** @return list<string> */
    private function relations(bool $withActions = false): array
    {
        return [
            'submitter:id,name',
            'department:id,name',
            'requestTypeVersion.requestType:id,name,slug',
            'workflowInstance.currentStep',
            ...($withActions ? ['workflowInstance.actions.actor:id,name', 'workflowInstance.actions.step:id,name'] : []),
        ];
    }
}
