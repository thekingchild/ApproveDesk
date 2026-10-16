<?php

namespace Tests\Feature\Audit;

use App\Jobs\PurgeExpiredDelegations;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowAction;
use App\Models\WorkflowTemplate;
use App\Services\WorkflowEngine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * RV-ENG-06: deleting a user must never rewrite workflow history.
 */
class AuditReferenceProtectionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_delegate_only_user_counts_as_audit_evidence_after_the_delegation_is_purged(): void
    {
        [$approver, $delegate] = $this->delegatedOnce();

        (new PurgeExpiredDelegations)->handle();

        $this->assertTrue($delegate->fresh()->hasPreservedAuditEvidence());
        $this->assertTrue($approver->fresh()->hasPreservedAuditEvidence());
    }

    public function test_database_refuses_to_null_the_delegate_on_a_history_row(): void
    {
        [, $delegate] = $this->delegatedOnce();
        (new PurgeExpiredDelegations)->handle();

        try {
            $delegate->delete();
            $this->fail('Deleting a user referenced by history succeeded.');
        } catch (QueryException) {
            // restrictOnDelete
        }

        $this->assertSame($delegate->id, WorkflowAction::query()->where('action', 'delegate')->sole()->delegated_to);
    }

    public function test_pruning_unconfirmed_accounts_keeps_anyone_in_request_history(): void
    {
        $approver = User::factory()->create();
        $unconfirmedDelegate = User::factory()->unverified()->create(['created_at' => now()->subDays(10)]);
        $unconfirmedStranger = User::factory()->unverified()->create(['created_at' => now()->subDays(10)]);
        foreach ([$unconfirmedDelegate, $unconfirmedStranger] as $registrant) {
            \App\Models\RegistrationOtpCode::query()->create(['email' => $registrant->email, 'code_hash' => 'x', 'expires_at' => now()->subDays(9), 'attempts' => 0]);
        }
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        app(WorkflowEngine::class)->delegate($instance, $approver, $unconfirmedDelegate, now()->addHour());

        $this->artisan('users:prune-unconfirmed', ['--hours' => 72])->assertSuccessful();

        $this->assertNotNull($unconfirmedDelegate->fresh());
        $this->assertNull($unconfirmedStranger->fresh());
    }

    /** @return array{0: User, 1: User} */
    private function delegatedOnce(): array
    {
        $approver = User::factory()->create();
        $delegate = User::factory()->create();
        WorkflowTemplate::factory()->default()->sequential([$approver])->create();
        $instance = app(WorkflowEngine::class)->initialize(Submission::factory()->create());
        app(WorkflowEngine::class)->delegate($instance, $approver, $delegate, now()->addHour());
        Carbon::setTestNow(now()->addHours(2));

        return [$approver, $delegate];
    }
}
