<?php

namespace App\Listeners;

use App\Enums\IntegrationDeliveryStatus;
use App\Events\WorkflowCompleted;
use App\Jobs\DeliverIntegrationAction;
use App\Models\IntegrationAction;
use App\Models\IntegrationDelivery;

final class DispatchIntegrationActions
{
    public function handle(WorkflowCompleted $event): void
    {
        $instance = $event->instance->loadMissing('submission.requestTypeVersion');
        $submission = $instance->submission;

        if ($submission === null) {
            return;
        }

        $actions = IntegrationAction::query()
            ->with('connector')
            ->where('is_enabled', true)
            ->whereHas('connector', fn ($query) => $query->where('is_enabled', true))
            ->get()
            ->filter(fn (IntegrationAction $action): bool => $action->appliesTo($submission));

        foreach ($actions as $action) {
            $key = 'completed:'.$submission->id.':'.$action->id;

            $delivery = IntegrationDelivery::query()->firstOrCreate(
                ['idempotency_key' => $key],
                [
                    'integration_action_id' => $action->id,
                    'submission_id' => $submission->id,
                    'status' => IntegrationDeliveryStatus::Pending,
                    'payload' => ['submission_id' => $submission->id, 'action' => $action->action->value],
                ],
            );

            if ($delivery->status === IntegrationDeliveryStatus::Delivered) {
                continue;
            }

            DeliverIntegrationAction::dispatch($delivery->id);
        }
    }
}
