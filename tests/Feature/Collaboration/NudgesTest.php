<?php

namespace Tests\Feature\Collaboration;

use App\Actions\Collaboration\NudgeApproversAction;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\SubmissionNudge;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Notifications\SubmissionNudgeNotification;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class NudgesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_submitter_can_nudge_the_current_approver(): void
    {
        Notification::fake();
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission])
            ->set('nudgeMessage', 'Please look at this today.')
            ->call('sendNudge')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('submission_nudges', [
            'submission_id' => $submission->id,
            'actor_id' => $submitter->id,
            'recipient_id' => $approver->id,
            'delayed_for_quiet_hours' => false,
        ]);
        Notification::assertSentTo($approver, SubmissionNudgeNotification::class);
    }

    public function test_nudge_is_rate_limited_and_append_only(): void
    {
        Notification::fake();
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        app(NudgeApproversAction::class)->execute($submission, $submitter, 'First');

        Livewire::actingAs($submitter)
            ->test('pages::submissions.show', ['submission' => $submission])
            ->call('sendNudge')
            ->assertHasErrors('nudgeMessage');

        $nudge = SubmissionNudge::query()->firstOrFail();
        $this->expectException(\RuntimeException::class);
        $nudge->update(['message' => 'tamper']);
    }

    public function test_quiet_hours_delay_delivery_until_the_window_opens(): void
    {
        Notification::fake();
        Setting::put('nudge.quiet_hours', json_encode(['start' => '22:00', 'end' => '07:00']));
        Carbon::setTestNow('2026-08-20 23:15:00');

        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->for($submitter, 'submitter')->create();
        app(WorkflowEngine::class)->initialize($submission);

        app(NudgeApproversAction::class)->execute($submission, $submitter, 'Overnight');

        $nudge = SubmissionNudge::query()->firstOrFail();
        $this->assertTrue($nudge->delayed_for_quiet_hours);
        $this->assertSame('2026-08-21 07:00:00', $nudge->scheduled_for->format('Y-m-d H:i:s'));
        Notification::assertSentTo($approver, SubmissionNudgeNotification::class);
    }

    public function test_admin_can_save_overnight_quiet_hours(): void
    {
        $admin = User::factory()->create()->assignRole('admin');

        Livewire::actingAs($admin)
            ->test('pages::settings.approvals')
            ->set('quietHoursStart', '22:00')
            ->set('quietHoursEnd', '07:00')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            ['start' => '22:00', 'end' => '07:00'],
            json_decode((string) Setting::get('nudge.quiet_hours'), true),
        );
    }
}
