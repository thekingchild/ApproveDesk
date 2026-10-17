<?php

namespace App\Jobs;

use App\Enums\IntegrationDeliveryStatus;
use App\Models\IntegrationDelivery;
use App\Services\Integrations\ConnectorRegistry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class DeliverIntegrationAction implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600];

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(ConnectorRegistry $registry): void
    {
        $delivery = DB::transaction(function (): ?IntegrationDelivery {
            $delivery = IntegrationDelivery::query()
                ->with(['action.connector', 'submission.submitter', 'submission.requestTypeVersion.requestType', 'submission.files'])
                ->lockForUpdate()
                ->find($this->deliveryId);

            if ($delivery === null || $delivery->status === IntegrationDeliveryStatus::Delivered) {
                return null;
            }

            $connector = $delivery->action?->connector;

            if ($connector === null || ! $connector->is_enabled || ! $delivery->action->is_enabled) {
                $delivery->update([
                    'status' => IntegrationDeliveryStatus::DeadLetter,
                    'last_error' => 'Connector or action was disabled when delivery was attempted.',
                    'next_attempt_at' => null,
                ]);

                return null;
            }

            if ($connector->last_request_at !== null
                && $connector->last_request_at->gt(now()->subSeconds(max(1, $connector->min_interval_seconds)))) {
                $delivery->update(['next_attempt_at' => now()->addSeconds($connector->min_interval_seconds)]);
                // Wait without counting a failed attempt; failed() covers running out of tries (RV-LAR-07).
                $this->release(max(1, $connector->min_interval_seconds));

                return null;
            }

            $delivery->increment('attempts');
            $connector->forceFill(['last_request_at' => now()])->save();

            return $delivery->fresh(['action.connector', 'submission.submitter', 'submission.requestTypeVersion.requestType', 'submission.files']);
        });

        if ($delivery === null) {
            return;
        }

        try {
            $connector = $delivery->action->connector;
            $driver = $registry->get($connector->type);
            $result = $driver->execute($delivery->action, $delivery->submission, $connector->credentialMap());
        } catch (Throwable $exception) {
            $this->failDelivery($delivery, $connector->redact($exception->getMessage()));
        }

        if (! $result->ok) {
            $this->failDelivery(
                $delivery,
                $result->error !== '' ? $result->error : 'The destination returned an error.',
                $result->status,
            );
        }

        $delivery->update([
            'status' => IntegrationDeliveryStatus::Delivered,
            'response_status' => $result->status,
            'response_body' => null,
            'last_error' => null,
            'delivered_at' => now(),
            'next_attempt_at' => null,
        ]);
        $connector->forceFill(['last_error' => null])->save();
    }

    /**
     * The queue gave up (timeout, crash, or out of tries): a delivery still
     * marked Pending would wait forever, so move it to the dead-letter list
     * where Operations can replay it (RV-LAR-07).
     */
    public function failed(?Throwable $exception): void
    {
        $delivery = IntegrationDelivery::query()->with('action.connector')->find($this->deliveryId);

        if ($delivery === null || $delivery->status !== IntegrationDeliveryStatus::Pending) {
            return;
        }

        $reason = 'The delivery job stopped: '.($exception?->getMessage() ?? 'unknown error');
        $connector = $delivery->action?->connector;

        $delivery->update([
            'status' => IntegrationDeliveryStatus::DeadLetter,
            'last_error' => Str::limit($connector?->redact($reason) ?? $reason, 2000, ''),
            'next_attempt_at' => null,
        ]);
    }

    private function failDelivery(
        IntegrationDelivery $delivery,
        string $error,
        ?int $status = null,
    ): never {
        $final = $delivery->attempts >= $this->tries;
        $redacted = $delivery->action->connector->redact($error);

        $delivery->update([
            'status' => $final ? IntegrationDeliveryStatus::DeadLetter : IntegrationDeliveryStatus::Pending,
            'response_status' => $status,
            'response_body' => null,
            'last_error' => Str::limit($redacted, 2000, ''),
            'next_attempt_at' => $final ? null : now()->addSeconds($this->backoff[$delivery->attempts - 1] ?? 3600),
        ]);
        $delivery->action->connector->forceFill(['last_error' => Str::limit($redacted, 2000, '')])->save();

        throw new RuntimeException('Integration delivery failed: '.$redacted);
    }
}
