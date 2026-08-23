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

                throw new RuntimeException('Connector rate limit: retry shortly.');
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
                $connector->redact(($result->error !== '' ? $result->error : 'Connector returned a failure.').' '.$result->body),
                $result->status,
                $connector->redact($result->body),
            );
        }

        $delivery->update([
            'status' => IntegrationDeliveryStatus::Delivered,
            'response_status' => $result->status,
            'response_body' => Str::limit($connector->redact($result->body), 2000, ''),
            'last_error' => null,
            'delivered_at' => now(),
            'next_attempt_at' => null,
        ]);
        $connector->forceFill(['last_error' => null])->save();
    }

    private function failDelivery(
        IntegrationDelivery $delivery,
        string $error,
        ?int $status = null,
        string $body = '',
    ): never {
        $final = $delivery->attempts >= $this->tries;
        $redacted = $delivery->action->connector->redact($error);

        $delivery->update([
            'status' => $final ? IntegrationDeliveryStatus::DeadLetter : IntegrationDeliveryStatus::Pending,
            'response_status' => $status,
            'response_body' => $body === '' ? null : Str::limit($body, 2000, ''),
            'last_error' => Str::limit($redacted, 2000, ''),
            'next_attempt_at' => $final ? null : now()->addSeconds($this->backoff[$delivery->attempts - 1] ?? 3600),
        ]);
        $delivery->action->connector->forceFill(['last_error' => Str::limit($redacted, 2000, '')])->save();

        throw new RuntimeException('Integration delivery failed: '.$redacted);
    }
}
