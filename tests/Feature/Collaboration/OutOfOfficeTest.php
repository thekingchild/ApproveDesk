<?php

namespace Tests\Feature\Collaboration;

use App\Actions\Collaboration\SaveOutOfOfficeAction;
use App\DTOs\DelegationData;
use App\DTOs\WorkflowActionData;
use App\Enums\WorkflowActionType;
use App\Enums\WorkflowStatus;
use App\Exceptions\CollaborationException;
use App\Exceptions\WorkflowException;
use App\Jobs\ApplyOutOfOfficeFallbacksJob;
use App\Models\Delegation;
use App\Models\OutOfOffice;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Services\Collaboration\OutOfOfficeFallback;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class OutOfOfficeTest extends TestCase
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

    public function test_active_ooo_auto_delegates_to_the_fallback(): void
    {
        Carbon::setTestNow('2026-08-20 09:00:00');
        $approver = User::factory()->create();
        $fallback = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $submission = Submission::factory()->create();

        app(SaveOutOfOfficeAction::class)->execute(
            $approver,
            now(),
            now()->addDays(2),
            $fallback->id,
            'On leave.',
        );

        $instance = app(WorkflowEngine::class)->initialize($submission);
        app(OutOfOfficeFallback::class)->applyToInstance($instance);

        $this->assertDatabaseHas('delegations', [
            'instance_id' => $instance->id,
            'delegator_id' => $approver->id,
            'delegate_id' => $fallback->id,
        ]);

        app(WorkflowEngine::class)->processAction($instance, new WorkflowActionData($fallback, WorkflowActionType::Approve, 'Covered.'));
        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
    }

    public function test_overlapping_and_circular_schedules_are_rejected(): void
    {
        $user = User::factory()->create();
        $fallback = User::factory()->create();
        $save = app(SaveOutOfOfficeAction::class);

        $save->execute($user, now(), now()->addDay(), $fallback->id);

        try {
            $save->execute($user, now()->addHours(2), now()->addDays(2), $fallback->id);
            $this->fail('Overlapping OOO must be rejected.');
        } catch (CollaborationException $exception) {
            $this->assertStringContainsString('overlaps', $exception->getMessage());
        }

        try {
            $save->execute($fallback, now(), now()->addDay(), $user->id);
            $this->fail('Circular fallback must be rejected.');
        } catch (CollaborationException $exception) {
            $this->assertStringContainsString('falls back to you', $exception->getMessage());
        }
    }

    public function test_future_delegation_keeps_delegator_until_start(): void
    {
        Carbon::setTestNow('2026-08-20 09:00:00');
        $approver = User::factory()->create();
        $delegate = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        app(WorkflowEngine::class)->delegate(
            $instance,
            new DelegationData(
                $approver,
                $delegate,
                now()->addDays(2),
                'Starts tomorrow.',
                now()->addDay(),
            ),
        );

        app(WorkflowEngine::class)->processAction($instance, new WorkflowActionData($approver, WorkflowActionType::Approve));
        $this->assertSame(WorkflowStatus::Completed, $instance->fresh()->status);
    }

    public function test_future_delegation_rejects_overlap_ooo_and_inverted_window(): void
    {
        Carbon::setTestNow('2026-08-20 09:00:00');
        $approver = User::factory()->create();
        $delegate = User::factory()->create();
        $other = User::factory()->create();
        $away = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        $engine = app(WorkflowEngine::class);

        $engine->delegate($instance, new DelegationData($approver, $delegate, now()->addDays(2), null, now()->addDay()));

        try {
            $engine->delegate($instance, new DelegationData($approver, $other, now()->addDays(3), null, now()->addHours(30)));
            $this->fail('Overlapping future delegation must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('already have an active delegation', $exception->getMessage());
        }

        try {
            $engine->delegate($instance, new DelegationData($approver, $other, now()->addHour(), null, now()->addHours(2)));
            $this->fail('Inverted window must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('start must be before', $exception->getMessage());
        }

        Delegation::factory()->create([
            'instance_id' => $instance->id,
            'step_id' => $instance->current_step_id,
            'delegator_id' => User::factory(),
            'delegate_id' => $other->id,
            'starts_at' => now()->addDays(4),
            'expires_at' => now()->addDays(6),
        ]);

        try {
            $engine->delegate($instance, new DelegationData($approver, $other, now()->addDays(5), null, now()->addDays(4)->addHour()));
            $this->fail('Overlapping incoming delegation must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('already holds a seat', $exception->getMessage());
        }

        OutOfOffice::factory()->create([
            'user_id' => $away->id,
            'starts_at' => now()->addDays(4),
            'ends_at' => now()->addDays(6),
            'fallback_user_id' => $delegate->id,
        ]);

        try {
            $engine->delegate($instance, new DelegationData($approver, $away, now()->addDays(5), null, now()->addDays(4)->addHour()));
            $this->fail('Delegation onto an OOO user must be rejected.');
        } catch (WorkflowException $exception) {
            $this->assertStringContainsString('out of office', $exception->getMessage());
        }
    }

    public function test_user_can_save_ooo_from_settings(): void
    {
        $user = User::factory()->create();
        $fallback = User::factory()->create(['name' => 'Pat Fallback']);

        Livewire::actingAs($user)
            ->test('pages::settings.out-of-office')
            ->set('startsAt', now()->addHour()->format('Y-m-d\TH:i'))
            ->set('endsAt', now()->addDays(2)->format('Y-m-d\TH:i'))
            ->set('fallbackQuery', 'Pat')
            ->call('chooseFallback', $fallback->id)
            ->set('message', 'Conference.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('user_out_of_office', [
            'user_id' => $user->id,
            'fallback_user_id' => $fallback->id,
            'message' => 'Conference.',
        ]);
    }

    public function test_scheduled_job_applies_fallbacks_when_ooo_starts(): void
    {
        Carbon::setTestNow('2026-08-20 09:00:00');
        $approver = User::factory()->create();
        $fallback = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());

        OutOfOffice::factory()->create([
            'user_id' => $approver->id,
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addDays(2),
            'fallback_user_id' => $fallback->id,
        ]);

        app(ApplyOutOfOfficeFallbacksJob::class)->handle(app(OutOfOfficeFallback::class));
        $this->assertSame(0, $instance->delegations()->count());

        Carbon::setTestNow('2026-08-20 10:05:00');
        app(ApplyOutOfOfficeFallbacksJob::class)->handle(app(OutOfOfficeFallback::class));

        $this->assertDatabaseHas('delegations', [
            'instance_id' => $instance->id,
            'delegate_id' => $fallback->id,
        ]);
    }
}
