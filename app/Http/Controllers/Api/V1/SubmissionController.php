<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Submissions\CreateSubmissionAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\DTOs\SubmissionData;
use App\Enums\AssigneeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IndexSubmissionRequest;
use App\Http\Requests\Api\V1\StoreSubmissionRequest;
use App\Http\Resources\Api\V1\SubmissionResource;
use App\Models\Submission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

class SubmissionController extends Controller
{
    public function index(IndexSubmissionRequest $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $validated = $request->validated();
        $roleIds = Role::query()->whereIn('name', $user->getRoleNames())->pluck('id')->all();
        $query = Submission::query()
            ->with(['submitter:id,name', 'department:id,name', 'requestTypeVersion.requestType:id,name,slug', 'workflowInstance.currentStep'])
            ->when(! $user->can('submissions.view.all'), function ($builder) use ($roleIds, $user): void {
                $builder->where(function ($builder) use ($roleIds, $user): void {
                    $builder->where('submitter_id', $user->id)
                        ->orWhereHas('workflowInstance.currentStep', function ($builder) use ($user, $roleIds): void {
                            $builder->where(function ($builder) use ($user, $roleIds): void {
                                $builder->where(function ($builder) use ($user): void {
                                    $builder->where('assignee_type', AssigneeType::User)
                                        ->whereJsonContains('assignee_ids', $user->id);
                                });

                                if ($user->department_id !== null) {
                                    $builder->orWhere(function ($builder) use ($user): void {
                                        $builder->where('assignee_type', AssigneeType::Department)
                                            ->whereJsonContains('assignee_ids', $user->department_id);
                                    });
                                }

                                if ($roleIds !== []) {
                                    $builder->orWhere(function ($builder) use ($roleIds): void {
                                        $builder->where('assignee_type', AssigneeType::Role)
                                            ->where(function ($builder) use ($roleIds): void {
                                                foreach ($roleIds as $roleId) {
                                                    $builder->orWhereJsonContains('assignee_ids', $roleId);
                                                }
                                            });
                                    });
                                }
                            });
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
            ->when(isset($validated['type']), fn ($builder) => $builder->where('type', $validated['type']))
            ->latest('updated_at');

        return SubmissionResource::collection($query->paginate($validated['per_page'] ?? 25)->withQueryString());
    }

    public function store(
        StoreSubmissionRequest $request,
        CreateSubmissionAction $createSubmission,
        SubmitSubmissionAction $submitSubmission,
    ): JsonResponse {
        $validated = $request->validated();
        $submission = $createSubmission->execute(
            SubmissionData::fromValidated($validated),
            $request->user(),
            ! ($validated['submit'] ?? false),
        );

        if ($validated['submit'] ?? false) {
            $submitSubmission->execute($submission);
        }

        return (new SubmissionResource($submission->load($this->relations())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Submission $submission): SubmissionResource
    {
        Gate::authorize('view', $submission);

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
