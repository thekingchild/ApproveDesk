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
use App\Services\SubmissionVisibility;
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
            ->tap(fn ($builder) => app(SubmissionVisibility::class)->constrain($builder, $user))
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
