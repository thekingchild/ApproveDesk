<?php

namespace App\Services\Workflow;

use App\Enums\AssigneeType;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

final class AssigneeDirectory
{
    public const RESULT_LIMIT = 20;

    public const MIN_QUERY_LENGTH = 2;

    /**
     * @param  array<int, int>  $excludeIds
     * @return Collection<int, object>
     */
    public function search(string $type, string $query, array $excludeIds = []): Collection
    {
        $term = trim($query);
        if (mb_strlen($term) < self::MIN_QUERY_LENGTH) {
            return collect();
        }

        $excludeIds = array_values(array_filter(array_map('intval', $excludeIds)));

        return match (AssigneeType::tryFrom($type)) {
            AssigneeType::User => $this->searchUsers($term, $excludeIds),
            AssigneeType::Role => $this->searchRoles($term, $excludeIds),
            AssigneeType::Department => $this->searchDepartments($term, $excludeIds),
            default => collect(),
        };
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, object>
     */
    public function selected(string $type, array $ids): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }

        return match (AssigneeType::tryFrom($type)) {
            AssigneeType::User => User::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'email']),
            AssigneeType::Role => Role::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']),
            AssigneeType::Department => Department::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']),
            default => collect(),
        };
    }

    /**
     * @param  array<int, int>  $excludeIds
     * @return Collection<int, User>
     */
    private function searchUsers(string $term, array $excludeIds): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        return User::query()
            ->where('is_active', true)
            ->when($excludeIds !== [], fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->orderBy('name')
            ->limit(self::RESULT_LIMIT)
            ->get(['id', 'name', 'email']);
    }

    /**
     * @param  array<int, int>  $excludeIds
     * @return Collection<int, Role>
     */
    private function searchRoles(string $term, array $excludeIds): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        return Role::query()
            ->when($excludeIds !== [], fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->where('name', 'like', $like)
            ->orderBy('name')
            ->limit(self::RESULT_LIMIT)
            ->get(['id', 'name']);
    }

    /**
     * @param  array<int, int>  $excludeIds
     * @return Collection<int, Department>
     */
    private function searchDepartments(string $term, array $excludeIds): Collection
    {
        $like = '%'.$this->escapeLike($term).'%';

        return Department::query()
            ->when($excludeIds !== [], fn ($query) => $query->whereNotIn('id', $excludeIds))
            ->where('name', 'like', $like)
            ->orderBy('name')
            ->limit(self::RESULT_LIMIT)
            ->get(['id', 'name']);
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
