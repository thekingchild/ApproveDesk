<?php

namespace App\Actions\Departments;

use App\DTOs\DepartmentData;
use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateDepartmentAction
{
    public function execute(Department $department, DepartmentData $data): Department
    {
        return DB::transaction(function () use ($department, $data): Department {
            /** @var Department $department */
            $department = Department::query()
                ->whereKey($department->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->guardAcyclicHierarchy($department, $data->parentId);

            $department->update([
                'name' => $data->name,
                'parent_id' => $data->parentId,
                'manager_id' => $data->managerId,
            ]);

            return $department->load(['parent', 'manager']);
        });
    }

    private function guardAcyclicHierarchy(Department $department, ?int $parentId): void
    {
        $visited = [];

        while ($parentId !== null) {
            if ($parentId === $department->id || in_array($parentId, $visited, true)) {
                throw ValidationException::withMessages([
                    'parentId' => __('A department cannot be its own ancestor.'),
                ]);
            }

            $visited[] = $parentId;
            $parentId = Department::query()
                ->whereKey($parentId)
                ->lockForUpdate()
                ->value('parent_id');
        }
    }
}
