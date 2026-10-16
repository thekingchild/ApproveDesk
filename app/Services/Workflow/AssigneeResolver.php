<?php

namespace App\Services\Workflow;

use App\Contracts\ExternalAssigneeResolver;
use App\Enums\AssigneeType;
use App\Enums\RequestFieldType;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplateStep;
use App\Support\SeparationOfDuties;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Models\Role;

final class AssigneeResolver
{
    /**
     * Per-batch memo of request-independent assignee sets (named users, roles,
     * departments), keyed by step. Null outside batch(): the engine and
     * policies always resolve fresh.
     *
     * @var array<string, Collection<int, User>>|null
     */
    private static ?array $memo = null;

    /**
     * Run list/dashboard work that resolves many steps with a shared memo, so
     * N requests on the same step cost one query instead of N (RV-PERF).
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function batch(callable $work): mixed
    {
        $outer = self::$memo;
        self::$memo ??= [];

        try {
            return $work();
        } finally {
            self::$memo = $outer;
        }
    }

    /**
     * @param  iterable<int, ExternalAssigneeResolver>  $external
     */
    public function __construct(private readonly iterable $external = []) {}

    /**
     * @return Collection<int, User>
     */
    public function resolve(WorkflowTemplateStep $step, ?Submission $submission = null): Collection
    {
        $users = $this->candidates($step, $submission);

        if ($submission !== null && SeparationOfDuties::enabled()) {
            $users = $users->reject(fn (User $user): bool => (int) $user->id === (int) $submission->submitter_id);
        }

        return new Collection($users->values()->all());
    }

    /**
     * Everyone the step's assignee rule selects, before separation of duties
     * removes the requester.
     *
     * @return Collection<int, User>
     */
    public function candidates(WorkflowTemplateStep $step, ?Submission $submission = null): Collection
    {
        $requestIndependent = in_array($step->assignee_type, [AssigneeType::User, AssigneeType::Role, AssigneeType::Department], true);
        $key = $step->id.':'.$step->assignee_type->value.':'.json_encode($step->assignee_ids ?? []);

        if ($requestIndependent && self::$memo !== null && isset(self::$memo[$key])) {
            return new Collection(self::$memo[$key]->all());
        }

        $users = match ($step->assignee_type) {
            AssigneeType::User => $this->namedUsers($step->assignee_ids ?? []),
            AssigneeType::Role => $this->roleUsers($step->assignee_ids ?? []),
            AssigneeType::Department => $this->departmentUsers($step->assignee_ids ?? []),
            AssigneeType::RequesterManager => $this->requesterManager($submission),
            AssigneeType::DepartmentManager => $this->departmentManager($submission),
            AssigneeType::FormField => $this->formFieldUser($step, $submission),
            AssigneeType::External => $this->externalUsers($step, $submission),
        };

        $users = new Collection($users->unique('id')->values()->all());

        if ($requestIndependent && self::$memo !== null) {
            self::$memo[$key] = $users;
        }

        return $users;
    }

    /**
     * True when the requester is the only person this step would otherwise resolve to.
     */
    public function onlyRequesterResolves(WorkflowTemplateStep $step, Submission $submission): bool
    {
        if (! SeparationOfDuties::enabled()) {
            return false;
        }

        $ids = $this->candidates($step, $submission)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $ids === [(int) $submission->submitter_id];
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
