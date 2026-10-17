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

        // RV-ARC-17: shown back in the zone it was entered in, not the server's.
        Livewire::actingAs($approver)
            ->test('pages::submissions.show', ['submission' => $submission->fresh()])
            ->set('browserTimezone', 'Asia/Tokyo')
            ->assertSee('16 Oct 2026 17:00 (Asia/Tokyo)');

        // 17:00 in Tokyo, whatever the server's own timezone is.
        $this->assertTrue(
            Delegation::query()->sole()->expires_at->equalTo(Carbon::parse('2026-10-16 17:00', 'Asia/Tokyo')),
            'The delegation must end at 17:00 Tokyo time.',
        );
    }

    public function test_rv_arc_17_out_of_office_periods_are_listed_in_the_zone_they_were_entered_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-16 00:00:00', 'UTC'));
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create()->assignRole('approver');

        Livewire::actingAs($user)
            ->test('pages::settings.out-of-office')
            ->set('browserTimezone', 'Asia/Tokyo')
            ->set('startsAt', '2026-10-20T09:00')
            ->set('endsAt', '2026-10-21T17:30')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('20 Oct 2026 09:00')
            ->assertSee('21 Oct 2026 17:30')
            ->assertDontSee('21 Oct 2026 08:30');
    }
}
