<?php

namespace App\Services\Workflow;

use App\Contracts\ExternalAssigneeResolver;
use App\Enums\AssigneeType;
use App\Enums\RequestFieldType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplateStep;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

final class AssigneeResolver
{
    /**
     * @param  iterable<int, ExternalAssigneeResolver>  $external
     */
    public function __construct(private readonly iterable $external = []) {}

    /**
     * @return Collection<int, User>
     */
    public function resolve(WorkflowTemplateStep $step, ?Submission $submission = null): Collection
    {
        $users = match ($step->assignee_type) {
            AssigneeType::User => $this->namedUsers($step->assignee_ids ?? []),
            AssigneeType::Role => $this->roleUsers($step->assignee_ids ?? []),
            AssigneeType::Department => $this->departmentUsers($step->assignee_ids ?? []),
            AssigneeType::RequesterManager => $this->requesterManager($submission),
            AssigneeType::DepartmentManager => $this->departmentManager($submission),
            AssigneeType::FormField => $this->formFieldUser($step, $submission),
            AssigneeType::External => $this->externalUsers($step, $submission),
        };

        return new Collection($users->unique('id')->values()->all());
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, User>
     */
    private function namedUsers(array $ids): Collection
    {
        return User::query()->where('is_active', true)->whereIn('id', $ids)->get();
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, User>
     */
    private function roleUsers(array $ids): Collection
    {
        $names = Role::query()->whereIn('id', $ids)->pluck('name')->all();

        if ($names === []) {
            return new Collection;
        }

        return User::query()->where('is_active', true)->role($names)->get();
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, User>
     */
    private function departmentUsers(array $ids): Collection
    {
        return User::query()->where('is_active', true)->whereIn('department_id', $ids)->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function requesterManager(?Submission $submission): Collection
    {
        $manager = $submission?->submitter?->manager;

        return $this->activeUser($manager);
    }

    /**
     * @return Collection<int, User>
     */
    private function departmentManager(?Submission $submission): Collection
    {
        $manager = $submission?->department?->manager
            ?? $submission?->submitter?->department?->manager;

        return $this->activeUser($manager);
    }

    /**
     * @return Collection<int, User>
     */
    private function formFieldUser(WorkflowTemplateStep $step, ?Submission $submission): Collection
    {
        $key = trim((string) data_get($step->assignee_config, 'field_key', ''));
        if ($submission === null || $key === '') {
            return new Collection;
        }

        $submission->loadMissing('fieldValues.field', 'fieldValues.userValue');
        $value = $submission->fieldValues->first(
            fn ($fieldValue): bool => $fieldValue->field?->key === $key
                && $fieldValue->field?->type === RequestFieldType::User,
        );

        return $this->activeUser($value?->userValue);
    }

    /**
     * @return Collection<int, User>
     */
    private function externalUsers(WorkflowTemplateStep $step, ?Submission $submission): Collection
    {
        $key = trim((string) data_get($step->assignee_config, 'resolver', ''));
        if ($submission === null || $key === '') {
            return new Collection;
        }

        foreach ($this->external as $resolver) {
            if ($resolver->key() === $key) {
                return new Collection($resolver->resolve($step, $submission)
                    ->filter(fn (User $user): bool => $user->is_active)
                    ->values()
                    ->all());
            }
        }

        return new Collection;
    }

    /**
     * @return Collection<int, User>
     */
    private function activeUser(?User $user): Collection
    {
        if ($user === null || ! $user->is_active) {
            return new Collection;
        }

        return new Collection([$user]);
    }
}
