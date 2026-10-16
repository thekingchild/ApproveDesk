<?php

namespace Tests\Feature;

use App\Models\WorkflowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
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

    /**
     * RV-ENG-07: bulk query-builder writes bypass model events, so the builder
     * itself must refuse them.
     */
    public function test_bulk_updates_and_deletes_are_refused(): void
    {
        $action = WorkflowAction::factory()->create(['comment' => 'Original']);

        foreach ([
            fn () => WorkflowAction::query()->whereKey($action->id)->update(['comment' => 'Tampered']),
            fn () => WorkflowAction::query()->whereKey($action->id)->delete(),
            fn () => WorkflowAction::query()->whereKey($action->id)->forceDelete(),
            fn () => WorkflowAction::query()->whereKey($action->id)->increment('step_id'),
        ] as $write) {
            try {
                $write();
                $this->fail('A bulk write to workflow_actions succeeded.');
            } catch (LogicException) {
                // expected
            }
        }

        $this->assertSame('Original', WorkflowAction::query()->findOrFail($action->id)->comment);
    }
}
