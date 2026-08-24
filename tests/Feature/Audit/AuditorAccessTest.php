<?php

namespace Tests\Feature\Audit;

use App\Actions\Audit\GrantAuditorAccessAction;
use App\Actions\Submissions\SubmitSubmissionAction;
use App\Enums\SubmissionStatus;
use App\Enums\WorkflowActionType;
use App\Models\AuditExportLog;
use App\Models\AuditorAccess;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AuditorAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $auditor;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
        $this->admin = User::factory()->create()->assignRole('admin');
        $this->auditor = User::factory()->create()->assignRole('auditor');
        $submitter = User::factory()->create()->assignRole('submitter');
        $approver = User::factory()->create()->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $this->submission = Submission::factory()->for($submitter, 'submitter')->create([
            'status' => SubmissionStatus::Draft,
        ]);
        app(SubmitSubmissionAction::class)->execute($this->submission);
    }

    public function test_administrator_can_grant_and_revoke_time_bounded_auditor_access(): void
    {
        Livewire::actingAs($this->admin)
            ->test('pages::admin.records')
            ->set('auditorUserId', $this->auditor->id)
            ->set('accessStartsAt', '2026-08-24 09:00')
            ->set('accessEndsAt', '2026-09-24 09:00')
            ->set('accessReason', 'Year-end sample')
            ->call('grantAccess')
            ->assertHasNoErrors();

        $grant = AuditorAccess::query()->where('user_id', $this->auditor->id)->sole();
        $this->assertSame('Year-end sample', $grant->reason);
        $this->assertNull($grant->revoked_at);

        Livewire::actingAs($this->admin)
            ->test('pages::admin.records')
            ->call('revokeAccess', $grant->id)
            ->assertHasNoErrors();

        $this->assertNotNull($grant->fresh()->revoked_at);
    }

    public function test_active_auditor_can_open_a_request_and_download_evidence(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        app(GrantAuditorAccessAction::class)->execute(
            $this->admin,
            $this->auditor,
            now()->subHour(),
            now()->addDay(),
            'Sample',
        );

        $this->actingAs($this->auditor)
            ->get(route('submissions.show', $this->submission))
            ->assertOk();

        $this->actingAs($this->auditor)
            ->get(route('submissions.evidence.download', $this->submission))
            ->assertOk();

        $this->assertSame(1, AuditExportLog::query()->where('user_id', $this->auditor->id)->count());
    }

    public function test_expired_or_revoked_auditor_cannot_open_a_foreign_request(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        $grant = app(GrantAuditorAccessAction::class)->execute(
            $this->admin,
            $this->auditor,
            now()->subDays(10),
            now()->subDay(),
            'Expired window',
        );

        $this->actingAs($this->auditor)
            ->get(route('submissions.show', $this->submission))
            ->assertForbidden();

        $grant->forceFill([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'revoked_at' => now(),
            'revoked_by' => $this->admin->id,
        ])->save();

        $this->actingAs($this->auditor)
            ->get(route('submissions.show', $this->submission))
            ->assertForbidden();
    }

    public function test_auditor_cannot_decide_or_manage_users(): void
    {
        Carbon::setTestNow('2026-08-24 12:00:00');
        app(GrantAuditorAccessAction::class)->execute(
            $this->admin,
            $this->auditor,
            now()->subHour(),
            now()->addDay(),
            'Sample',
        );

        $this->actingAs($this->auditor)->get(route('admin.users.index'))->assertForbidden();

        $instance = $this->submission->fresh()->workflowInstance()->firstOrFail();
        $this->expectException(\Throwable::class);
        app(WorkflowEngine::class)->processAction($instance, $this->auditor, WorkflowActionType::Approve);
    }
}
