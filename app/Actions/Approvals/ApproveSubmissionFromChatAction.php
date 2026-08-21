<?php

namespace App\Actions\Approvals;

use App\Enums\WorkflowActionType;
use App\Exceptions\WorkflowException;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\Notifications\ChatActionToken;
use App\Services\WorkflowEngine;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class ApproveSubmissionFromChatAction
{
    public function __construct(private readonly WorkflowEngine $engine) {}

    public function execute(string $token): WorkflowInstance
    {
        $claims = ChatActionToken::parse($token);
        $instance = WorkflowInstance::query()->with(['currentStep', 'submission'])->find($claims['instance_id']);
        $approver = User::query()->find($claims['approver_id']);

        if ($instance === null || $approver === null) {
            throw new InvalidArgumentException('This approval action is no longer valid.');
        }

        if ($instance->current_step_id !== $claims['step_id']) {
            throw new WorkflowException('This approval action is no longer current.');
        }

        Gate::forUser($approver)->authorize('approveViaEmail', $instance->submission);

        return $this->engine->processAction(
            $instance,
            $approver,
            WorkflowActionType::Approve,
            'Approved through a signed chat action.',
        );
    }
}
