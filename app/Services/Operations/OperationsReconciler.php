<?php

namespace App\Services\Operations;

use App\DTOs\Operations\ReconciliationReport;
use App\Enums\IntegrationDeliveryStatus;
use App\Enums\SubmissionStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WorkflowStatus;
use App\Models\IntegrationDelivery;
use App\Models\Submission;
use App\Models\WebhookDelivery;
use App\Models\WorkflowInstance;

final class OperationsReconciler
{
    public function run(): ReconciliationReport
    {
        $findings = [];

        $submittedWithoutInstance = Submission::query()
            ->whereIn('status', [
                SubmissionStatus::Pending,
                SubmissionStatus::InReview,
                SubmissionStatus::AwaitingInformation,
            ])
            ->whereDoesntHave('workflowInstance')
            ->count();

        if ($submittedWithoutInstance > 0) {
            $findings[] = $submittedWithoutInstance.' submitted request(s) have no workflow instance.';
        }

        $activeMissingStep = WorkflowInstance::query()
            ->where('status', WorkflowStatus::Active)
            ->whereNull('current_step_id')
            ->count();

        if ($activeMissingStep > 0) {
            $findings[] = $activeMissingStep.' active workflow(s) have no current step.';
        }

        $deliveredWithRetry = WebhookDelivery::query()
            ->where('status', WebhookDeliveryStatus::Delivered)
            ->whereNotNull('next_attempt_at')
            ->count()
            + IntegrationDelivery::query()
                ->where('status', IntegrationDeliveryStatus::Delivered)
                ->whereNotNull('next_attempt_at')
                ->count();

        if ($deliveredWithRetry > 0) {
            $findings[] = $deliveredWithRetry.' delivered item(s) still have a retry time set.';
        }

        return new ReconciliationReport(
            findings: $findings,
            submittedWithoutInstance: $submittedWithoutInstance,
            activeMissingStep: $activeMissingStep,
            deliveredWithRetryScheduled: $deliveredWithRetry,
        );
    }
}
