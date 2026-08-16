<?php

namespace Tests\Feature;

use App\Models\WorkflowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class WorkflowActionImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_actions_cannot_be_updated(): void
    {
        $action = WorkflowAction::factory()->create();

        $this->expectException(RuntimeException::class);

        $action->update(['comment' => 'rewriting history']);
    }

    public function test_workflow_actions_cannot_be_deleted(): void
    {
        $action = WorkflowAction::factory()->create();

        $this->expectException(RuntimeException::class);

        $action->delete();
    }

    public function test_workflow_actions_have_no_updated_at_timestamp(): void
    {
        $action = WorkflowAction::factory()->create();

        $this->assertNull($action->updated_at);
        $this->assertNotNull($action->created_at);
    }
}
