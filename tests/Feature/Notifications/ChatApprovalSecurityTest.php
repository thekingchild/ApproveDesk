<?php

namespace Tests\Feature\Notifications;

use App\Actions\Approvals\ApproveSubmissionFromChatAction;
use App\DTOs\OutboundNotification;
use App\Enums\WorkflowStatus;
use App\Models\Setting;
use App\Models\Submission;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Services\Notifications\ChatActionToken;
use App\Services\Notifications\ChatCard;
use App\Services\WorkflowEngine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * RV-SEC-10: one-click chat approvals honour the second-factor setting and
 * can only be used by the named approver's own Slack account.
 */
class ChatApprovalSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $approver;

    private Submission $submission;

    private WorkflowInstance $instance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->approver = User::factory()->create(['slack_user_id' => 'U0APPROVER'])->assignRole('approver');
        WorkflowTemplate::factory()->default()->sequential([$this->approver])->create();
        $this->submission = Submission::factory()->create();
        $this->instance = app(WorkflowEngine::class)->initialize($this->submission);
    }

    public function test_slack_click_from_another_account_is_refused(): void
    {
        $token = ChatActionToken::issue($this->instance, $this->instance->currentStep, $this->approver);

        try {
            app(ApproveSubmissionFromChatAction::class)->execute($token, 'U0SOMEONEELSE');
            $this->fail('A different Slack account approved.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame(WorkflowStatus::Active, $this->instance->fresh()->status);

        app(ApproveSubmissionFromChatAction::class)->execute($token, 'U0APPROVER');
        $this->assertSame(WorkflowStatus::Completed, $this->instance->fresh()->status);
    }

    public function test_card_issued_before_second_factor_was_required_cannot_approve(): void
    {
        $token = ChatActionToken::issue($this->instance, $this->instance->currentStep, $this->approver);
        Setting::put('approvals.email_require_second_factor', '1');

        $this->expectException(InvalidArgumentException::class);

        app(ApproveSubmissionFromChatAction::class)->execute($token, 'U0APPROVER');
    }

    public function test_cards_offer_the_confirmation_page_instead_of_one_click_approve_when_a_second_factor_is_required(): void
    {
        $message = new OutboundNotification('action_required', 'Approval needed', ['Please review.'], 'https://example.test', 'Review', ['submission_id' => $this->submission->id]);

        $this->assertTrue(ChatCard::actionFor($this->approver, $message)['showApprove']);

        Setting::put('approvals.email_require_second_factor', '1');
        $card = ChatCard::actionFor($this->approver, $message);

        $this->assertFalse($card['showApprove']);
        $this->assertNull($card['token']);
        $this->assertNotNull($card['confirmUrl']);
    }
}
