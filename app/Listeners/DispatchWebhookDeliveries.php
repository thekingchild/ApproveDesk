<?php

namespace App\Listeners;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEvent;
use App\Events\SubmissionCreated;
use App\Events\WorkflowAdvanced;
use App\Events\WorkflowCompleted;
use App\Events\WorkflowDelegated;
use App\Events\WorkflowInformationRequested;
use App\Events\WorkflowRejected;
use App\Events\WorkflowReturned;
use App\Jobs\DeliverWebhook;
use App\Models\Delegation;
use App\Models\Submission;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\WorkflowInstance;
use App\Services\WebhookPayloadFactory;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Creates immutable delivery records; the queued job performs network I/O. */
final class DispatchWebhookDeliveries
{
    public function __construct(private readonly WebhookPayloadFactory $payloadFactory) {}

    public function handle(object $event): void
    {
        [$webhookEvent, $submission, $instance, $delegation] = $this->resolve($event);

        WebhookEndpoint::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (WebhookEndpoint $endpoint): bool => in_array($webhookEvent->value, $endpoint->events, true))
            ->each(function (WebhookEndpoint $endpoint) use ($webhookEvent, $submission, $instance, $delegation): void {
                $delivery = WebhookDelivery::create([
                    'endpoint_id' => $endpoint->id,
                    'event_id' => (string) Str::uuid(),
                    'event' => $webhookEvent->value,
                    'payload' => $this->payloadFactory->make($webhookEvent, $submission, $instance, $delegation),
                    'status' => WebhookDeliveryStatus::Pending,
                ]);

                DeliverWebhook::dispatch($delivery->id)->afterCommit();
            });
    }

    /** @return array{WebhookEvent, Submission, ?WorkflowInstance, ?Delegation} */
    private function resolve(object $event): array
    {
        return match ($event::class) {
            SubmissionCreated::class => [
                WebhookEvent::SubmissionCreated,
                $event->submission,
                $event->instance,
                null,
            ],
            WorkflowAdvanced::class => $this->fromInstance(WebhookEvent::WorkflowAdvanced, $event->instance),
            WorkflowCompleted::class => $this->fromInstance(WebhookEvent::WorkflowCompleted, $event->instance),
            WorkflowRejected::class => $this->fromInstance(WebhookEvent::WorkflowRejected, $event->instance),
            WorkflowReturned::class => $this->fromInstance(WebhookEvent::WorkflowReturned, $event->instance),
            WorkflowInformationRequested::class => $this->fromInstance(WebhookEvent::WorkflowInformationRequested, $event->instance),
            WorkflowDelegated::class => [
                WebhookEvent::WorkflowDelegated,
                $event->delegation->instance->submission,
                $event->delegation->instance,
                $event->delegation,
            ],
            default => throw new InvalidArgumentException('Unsupported webhook event: '.$event::class),
        };
    }

    /** @return array{WebhookEvent, Submission, WorkflowInstance, null} */
    private function fromInstance(WebhookEvent $event, WorkflowInstance $instance): array
    {
        return [$event, $instance->submission, $instance, null];
    }
}
