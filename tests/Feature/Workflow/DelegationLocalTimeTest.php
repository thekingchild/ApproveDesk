<?php

namespace Tests\Feature\Workflow;

use App\Models\Delegation;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * RV-BUG-03: a delegation window entered on the request page is read in the
 * approver's own timezone.
 */
class DelegationLocalTimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_delegation_expiry_uses_the_browser_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 00:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $approver = User::factory()->create()->assignRole('approver');
        $delegate = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();
        app(WorkflowEngine::class)->initialize($submission);

        Livewire::actingAs($approver)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->set('browserTimezone', 'Asia/Tokyo')
            ->set('delegateUserId', (string) $delegate->id)
            ->set('delegationExpiresAt', '2026-10-16T17:00')
            ->call('delegateApproval')
            ->assertHasNoErrors();

        // 17:00 in Tokyo, whatever the server's own timezone is.
        $this->assertTrue(
            Delegation::query()->sole()->expires_at->equalTo(Carbon::parse('2026-10-16 17:00', 'Asia/Tokyo')),
            'The delegation must end at 17:00 Tokyo time.',
        );
    }
}
