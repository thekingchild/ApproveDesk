<?php

namespace App\Actions\Departments;

use App\DTOs\DepartmentData;
use App\Models\Department;

final class CreateDepartmentAction
{
    public function execute(DepartmentData $data): Department
    {
        return Department::create([
            'name' => $data->name,
            'parent_id' => $data->parentId,
            'manager_id' => $data->managerId,
        ])->load(['parent', 'manager']);
    }
}
