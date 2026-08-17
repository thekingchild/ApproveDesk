<?php

namespace App\Services;

use App\Enums\WebhookEvent;
use App\Models\Delegation;
use App\Models\Submission;
use App\Models\WorkflowInstance;

final class WebhookPayloadFactory
{
    /**
     * @return array<string, mixed>
     */
    public function make(
        WebhookEvent $event,
        Submission $submission,
        ?WorkflowInstance $instance = null,
        ?Delegation $delegation = null,
    ): array {
        $submission->loadMissing(['submitter:id,name,email', 'department:id,name']);
        $instance?->loadMissing(['currentStep:id,name,step_order']);

        return [
            'event' => $event->value,
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'submission' => [
                    'id' => $submission->id,
                    'reference_number' => $submission->reference_number,
                    'type' => $submission->type->value,
                    'title' => $submission->title,
                    'body' => $submission->body,
                    'priority' => $submission->priority->value,
                    'status' => $submission->status->value,
                    'due_date' => $submission->due_date?->toDateString(),
                    'submitter' => [
                        'id' => $submission->submitter->id,
                        'name' => $submission->submitter->name,
                        'email' => $submission->submitter->email,
                    ],
                    'department' => $submission->department === null ? null : [
                        'id' => $submission->department->id,
                        'name' => $submission->department->name,
                    ],
                ],
                'workflow' => $instance === null ? null : [
                    'id' => $instance->id,
                    'status' => $instance->status->value,
                    'current_step' => $instance->currentStep === null ? null : [
                        'id' => $instance->currentStep->id,
                        'name' => $instance->currentStep->name,
                        'order' => $instance->currentStep->step_order,
                    ],
                ],
                'delegation' => $delegation === null ? null : [
                    'id' => $delegation->id,
                    'delegator_id' => $delegation->delegator_id,
                    'delegate_id' => $delegation->delegate_id,
                    'expires_at' => $delegation->expires_at->toIso8601String(),
                ],
            ],
        ];
    }
}
