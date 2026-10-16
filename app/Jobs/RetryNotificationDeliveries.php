<?php

namespace App\Jobs;

use App\DTOs\OutboundNotification;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationKind;
use App\Models\NotificationDelivery;
use App\Models\Submission;
use App\Models\User;
use App\Services\ApproverWorkbench;
use App\Services\Collaboration\QuietHours;
use App\Services\Notifications\ChannelRegistry;
use App\Services\Notifications\NotificationDeliveryRecorder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RetryNotificationDeliveries implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Each external send can take 10-20 s (HTTP timeouts), and the worker is
     * killed at 90 s. Take a small batch and stop starting new sends once the
     * budget is spent; the rest wait for the next 15-minute run (RV-PERF-18).
     */
    public const BATCH_SIZE = 20;

    public const TIME_BUDGET_SECONDS = 45;

    /**
     * One copy queued or running at a time; the schedule's withoutOverlapping()
     * only guards dispatch, so copies piled up when the worker lagged (RV-OPS-02).
     * The lock lapses after this many seconds if a worker is killed mid-job.
     */
    public int $uniqueFor = 900;

    public function handle(
        QuietHours $quietHours,
        ChannelRegistry $registry,
        NotificationDeliveryRecorder $recorder,
        ApproverWorkbench $workbench,
    ): void {
        if ($quietHours->isQuiet()) {
            return;
        }

        $startedAt = microtime(true);

        // Deferred rows wait for quiet hours to end; Failed rows only come back
        // when the recorder scheduled a retry for them.
        $deliveries = NotificationDelivery::query()
            ->where(function ($query): void {
                $query->where('status', NotificationDeliveryStatus::Deferred->value)
                    ->orWhere(fn ($failed) => $failed
                        ->where('status', NotificationDeliveryStatus::Failed->value)
                        ->whereNotNull('next_retry_at'));
            })
            ->where(function ($query): void {
                $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now());
            })
            ->where('attempts', '<', 3)
            ->oldest('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($deliveries as $delivery) {
            if (microtime(true) - $startedAt >= self::TIME_BUDGET_SECONDS) {
                break;
            }

            $user = User::query()->find($delivery->user_id);
            if ($user === null) {
                continue;
            }

            if (! $user->canReceiveNotifications()) {
                $recorder->markSkipped($delivery, 'Recipient is no longer active.');

                continue;
            }

            try {
                $driver = $registry->driver($delivery->channel);
                if (! $driver->isConfigured() || ! $driver->canReceive($user)) {
                    $recorder->markSkipped($delivery, 'Recipient can no longer receive this channel.');

                    continue;
                }

                $payload = $delivery->payload ?? [];
                if (! $this->stillCurrent($delivery, $payload, $user, $workbench)) {
                    $recorder->markSkipped($delivery, 'The request has moved on; this message is no longer current.');

                    continue;
                }

                // Re-render the real copy and a fresh signed link for the current step (RV-BUG-16).
                $message = OutboundNotification::fromPayload($payload, $user, $delivery->kind);
            } catch (Throwable $exception) {
                $recorder->markFailed($delivery, $exception->getMessage(), true);

                continue;
            }

            $recorder->deliver($driver, $user, $message, $delivery);
        }
    }

    /**
     * A held approval request or reminder is only worth sending if the person
     * can still act on the same step it was about (RV-BUG-16).
     *
     * @param  array<string, mixed>  $payload
     */
    private function stillCurrent(NotificationDelivery $delivery, array $payload, User $user, ApproverWorkbench $workbench): bool
    {
        if (! in_array($delivery->kind, [NotificationKind::ActionRequired->value, NotificationKind::Nudge->value], true)) {
            return true;
        }

        $submissionId = $payload['submission_id'] ?? $delivery->submission_id;
        $instance = is_numeric($submissionId)
            ? Submission::query()->find((int) $submissionId)?->workflowInstance()->first()
            : null;

        if ($instance === null) {
            return false;
        }

        // The step was (re)entered after this message was queued: a fresh one was sent then.
        if ($instance->step_started_at !== null
            && $delivery->created_at !== null
            && $instance->step_started_at->greaterThan($delivery->created_at)) {
            return false;
        }

        return $workbench->canAct($instance, $user);
    }
}
