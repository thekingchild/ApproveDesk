<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowTemplate;
use Illuminate\Database\Seeder;

/**
 * Development-only demo data: departments, one user per role, and a default
 * two-level sequential template. Never runs in production environments —
 * production creates its first admin through the /install wizard.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        $finance = Department::factory()->create(['name' => 'Finance']);
        $operations = Department::factory()->create(['name' => 'Operations']);

        $head = User::factory()->create([
            'name' => 'Amina Head',
            'email' => 'head@example.com',
            'department_id' => $finance->id,
        ])->assignRole('department-head');

        $director = User::factory()->create([
            'name' => 'David Director',
            'email' => 'director@example.com',
            'department_id' => $finance->id,
        ])->assignRole('department-head');

        User::factory()->create([
            'name' => 'Sam Submitter',
            'email' => 'submitter@example.com',
            'department_id' => $operations->id,
        ])->assignRole('submitter');

        User::factory()->create([
            'name' => 'Ada Admin',
            'email' => 'admin@example.com',
        ])->assignRole('admin');

        WorkflowTemplate::factory()
            ->default()
            ->sequential([$head, $director])
            ->create([
                'name' => 'Standard Two-Level Approval',
                'description' => 'Department head review, then director sign-off.',
                'created_by' => $head->id,
            ]);
    }
}
