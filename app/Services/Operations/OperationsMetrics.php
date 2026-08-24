<?php

namespace App\Services\Operations;

use App\DTOs\Operations\OperationsHealth;
use App\Enums\IntegrationDeliveryStatus;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\WebhookDeliveryStatus;
use App\Models\IntegrationConnector;
use App\Models\IntegrationDelivery;
use App\Models\NotificationDelivery;
use App\Models\OpsSnapshot;
use App\Models\Setting;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class OperationsMetrics
{
    public function health(): OperationsHealth
    {
        $heartbeat = Setting::get('ops.scheduler_heartbeat_at');
        $heartbeatAt = $heartbeat !== null && $heartbeat !== '' ? Carbon::parse($heartbeat) : null;

        return new OperationsHealth(
            schedulerHeartbeatAt: $heartbeatAt,
            schedulerFresh: $heartbeatAt !== null && $heartbeatAt->greaterThan(now()->subMinutes(5)),
            pendingJobs: $this->pendingJobs(),
            failedJobs: $this->failedJobs(),
            deadLetterWebhooks: $this->deadLetterWebhooks(),
            deadLetterIntegrations: $this->deadLetterIntegrations(),
            failedNotifications: $this->failedNotifications(),
            stuckWorkflows: app(StuckWorkflowDiagnostics::class)->count(),
            unhealthyConnectors: $this->unhealthyConnectors(),
            failingWebhookEndpoints: $this->failingWebhookEndpoints(),
            oldestPendingSeconds: $this->oldestPendingSeconds(),
        );
    }

    public function recordSnapshot(): OpsSnapshot
    {
        $health = $this->health();

        return OpsSnapshot::query()->create([
            'recorded_at' => now(),
            'pending_jobs' => $health->pendingJobs,
            'failed_jobs' => $health->failedJobs,
            'dead_letter_webhooks' => $health->deadLetterWebhooks,
            'dead_letter_integrations' => $health->deadLetterIntegrations,
            'failed_notifications' => $health->failedNotifications,
            'stuck_workflows' => $health->stuckWorkflows,
            'oldest_pending_seconds' => $health->oldestPendingSeconds,
        ]);
    }

    public function pendingJobs(): int
    {
        return Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;
    }

    public function failedJobs(): int
    {
        return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
    }

    public function deadLetterWebhooks(): int
    {
        return WebhookDelivery::query()->where('status', WebhookDeliveryStatus::Failed)->count();
    }

    public function deadLetterIntegrations(): int
    {
        return IntegrationDelivery::query()->where('status', IntegrationDeliveryStatus::DeadLetter)->count();
    }

    public function failedNotifications(): int
    {
        return NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Failed)->count();
    }

    public function unhealthyConnectors(): int
    {
        return IntegrationConnector::query()
            ->where('is_enabled', true)
            ->whereNotNull('last_error')
            ->where('last_error', '!=', '')
            ->count();
    }

    public function failingWebhookEndpoints(): int
    {
        return WebhookEndpoint::query()
            ->where('is_active', true)
            ->whereHas('deliveries', function ($query): void {
                $query->where('status', WebhookDeliveryStatus::Failed);
            })
            ->count();
    }

    public function oldestPendingSeconds(): ?int
    {
        if (! Schema::hasTable('jobs')) {
            return null;
        }

        $oldest = DB::table('jobs')->min('created_at');

        if ($oldest === null) {
            return null;
        }

        return max(0, now()->getTimestamp() - (int) $oldest);
    }
}
