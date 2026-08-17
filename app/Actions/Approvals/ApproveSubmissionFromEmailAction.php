<?php

namespace App\Actions\Approvals;

use App\Enums\WorkflowActionType;
use App\Models\User;
use App\Models\WorkflowInstance;
use App\Services\WorkflowEngine;

final readonly class ApproveSubmissionFromEmailAction
{
    public function __construct(private WorkflowEngine $engine) {}

    public function execute(WorkflowInstance $instance, User $approver): WorkflowInstance
    {
        return $this->engine->processAction(
            $instance,
            $approver,
            WorkflowActionType::Approve,
            'Approved through a secure email link.',
        );
    }
}
