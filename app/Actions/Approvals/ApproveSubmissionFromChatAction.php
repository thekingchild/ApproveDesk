<?php

namespace App\Actions\Approvals;

use App\Enums\WorkflowActionType;
use App\Exceptions\WorkflowException;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Notifications\ChatActionToken;
use App\Services\WorkflowEngine;
use App\Support\EmailApprovalLinks;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class ApproveSubmissionFromChatAction
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    /**
     * @param  string|null  $slackUserId  the Slack account that clicked, when the action came from Slack
     */
    public function execute(string $token, ?string $slackUserId = null): WorkflowInstance
    {
        // Cards issued before the setting was turned on must not bypass it (RV-SEC-10).
        if (EmailApprovalLinks::requiresSecondFactor()) {
            throw new InvalidArgumentException('Open the request in ApproveDesk to approve; sign-in and a second factor are required.');
        }

        $claims = ChatActionToken::parse($token);
        $instance = WorkflowInstance::query()->with(['currentStep', 'submission'])->find($claims['instance_id']);
        $approver = User::query()->find($claims['approver_id']);

        if ($instance === null || $approver === null) {
            throw new InvalidArgumentException('This approval action is no longer valid.');
        }

        // A forwarded or shared Slack card must not approve as someone else.
        if ($slackUserId !== null && ($approver->slack_user_id === null || ! hash_equals($approver->slack_user_id, $slackUserId))) {
            throw new InvalidArgumentException('Only the named approver can approve this request.');
        }

        if ($instance->current_step_id !== $claims['step_id']) {
            throw new WorkflowException('This approval action is no longer current.');
        }

        if (Gate::forUser($approver)->denies('approveViaEmail', $instance->submission)) {
            throw new WorkflowException('This request is not waiting for your decision.');
        }

        return $this->engine->processAction(
            $instance,
            $approver,
            WorkflowActionType::Approve,
            'Approved through a signed chat action.',
            expectedStepId: (int) $claims['step_id'],
        );
    }
}
