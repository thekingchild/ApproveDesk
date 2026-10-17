<?php

namespace App\Services\Operations;

use App\DTOs\Operations\DeadLetterItem;
use App\Enums\IntegrationDeliveryStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Models\IntegrationDelivery;
use App\Models\NotificationDelivery;
use App\Models\WebhookDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class DeadLetterInbox
{
    /**
     * @return Collection<int, DeadLetterItem>
     */
    public function items(int $limit = 40): Collection
    {
        return collect()
            ->concat($this->webhooks())
            ->concat($this->integrations())
            ->concat($this->notifications())
            ->concat($this->failedJobs())
            ->sortByDesc(fn (DeadLetterItem $item): int => $item->failedAt?->getTimestamp() ?? 0)
            ->take($limit)
            ->values();
    }

    /**
     * @return Collection<int, DeadLetterItem>
     */
    private function webhooks(): Collection
    {
        return WebhookDelivery::query()
            ->with('endpoint:id,name')
            ->where('status', WebhookDeliveryStatus::Failed)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (WebhookDelivery $delivery): DeadLetterItem => new DeadLetterItem(
                type: 'webhook',
                id: (string) $delivery->id,
                label: 'Webhook · '.($delivery->endpoint?->name ?? $delivery->event->value),
                error: $delivery->last_error,
                failedAt: $delivery->updated_at,
            ));
    }

    /**
     * @return Collection<int, DeadLetterItem>
     */
    private function integrations(): Collection
    {
        return IntegrationDelivery::query()
            ->with(['action.connector:id,name', 'submission:id,reference_number'])
            ->where('status', IntegrationDeliveryStatus::DeadLetter)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (IntegrationDelivery $delivery): DeadLetterItem => new DeadLetterItem(
                type: 'integration',
                id: (string) $delivery->id,
                label: 'Integration · '.($delivery->action?->connector?->name ?? 'action').' · '.($delivery->submission?->reference_number ?? $delivery->id),
                error: $delivery->last_error,
                failedAt: $delivery->updated_at,
            ));
    }

    /**
     * @return Collection<int, DeadLetterItem>
     */
    private function notifications(): Collection
    {
        return NotificationDelivery::query()
            ->with('user:id,name,email')
            ->where('status', NotificationDeliveryStatus::Failed)
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (NotificationDelivery $delivery): DeadLetterItem => new DeadLetterItem(
                type: 'notification',
                id: (string) $delivery->id,
                label: 'Notice · '.$delivery->channel.' · '.($delivery->user?->name ?? $delivery->kind),
                error: $delivery->last_error,
                failedAt: $delivery->updated_at,
            ));
    }

    /**
     * @return Collection<int, DeadLetterItem>
     */
    private function failedJobs(): Collection
    {
        if (! Schema::hasTable('failed_jobs')) {
            return collect();
        }

        return collect(DB::table('failed_jobs')->orderByDesc('id')->limit(20)->get())
            ->map(function (object $job): DeadLetterItem {
                $payload = json_decode((string) $job->payload, true);
                $name = is_array($payload) ? (string) ($payload['displayName'] ?? $payload['data']['commandName'] ?? 'Queued job') : 'Queued job';

                return new DeadLetterItem(
                    type: 'job',
                    id: (string) $job->uuid,
                    label: 'Queue · '.$name,
                    error: $this->firstExceptionLine((string) $job->exception),
                    failedAt: isset($job->failed_at) ? Carbon::parse($job->failed_at) : null,
                );
            });
    }

    private function firstExceptionLine(string $exception): string
    {
        $line = strtok($exception, "\n") ?: $exception;

        return mb_substr($line, 0, 240);
    }
}
