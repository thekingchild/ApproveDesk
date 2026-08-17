<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowTemplateStepTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_assignees_resolve_directly(): void
    {
        $assigned = User::factory()->count(2)->create();
        $outsider = User::factory()->create();

        $step = WorkflowTemplateStep::factory()->create([
            'assignee_type' => 'user',
            'assignee_ids' => $assigned->pluck('id')->all(),
        ]);

        $resolved = $step->assignedUsers();

        $this->assertEqualsCanonicalizing(
            $assigned->pluck('id')->all(),
            $resolved->pluck('id')->all(),
        );
        $this->assertFalse($resolved->contains($outsider));
    }

    public function test_role_assignees_expand_to_role_members(): void
    {
        Role::findOrCreate('auditor');

        $member = User::factory()->create()->assignRole('auditor');
        User::factory()->create(); // not an auditor

        $role = Role::findByName('auditor');
        $step = WorkflowTemplateStep::factory()->create([
            'assignee_type' => 'role',
            'assignee_ids' => [$role->id],
        ]);

        $resolved = $step->assignedUsers();

        $this->assertTrue($resolved->contains($member));
        $this->assertCount(1, $resolved);
    }

    public function test_department_assignees_expand_to_members(): void
    {
        $department = Department::factory()->has(User::factory()->count(3))->create();
        User::factory()->create();

        $step = WorkflowTemplateStep::factory()->create([
            'assignee_type' => 'department',
            'assignee_ids' => [$department->id],
        ]);

        $this->assertCount(3, $step->assignedUsers());
    }

    public function test_inactive_users_are_not_resolved_as_assignees(): void
    {
        $active = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);

        $step = WorkflowTemplateStep::factory()->create([
            'assignee_type' => 'user',
            'assignee_ids' => [$active->id, $inactive->id],
        ]);

        $this->assertSame([$active->id], $step->assignedUsers()->pluck('id')->all());
    }

    public function test_template_steps_are_ordered_by_step_order(): void
    {
        $template = WorkflowTemplate::factory()
            ->sequential([User::factory(), User::factory(), User::factory()])
            ->create();

        $orders = $template->steps->pluck('step_order')->all();

        $this->assertSame([1, 2, 3], $orders);
    }
}
