<?php

namespace App\Jobs;

use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Support\OutboundDestination;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900, 3600];

    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = DB::transaction(function (): ?WebhookDelivery {
            /** @var WebhookDelivery|null $delivery */
            $delivery = WebhookDelivery::query()
                ->with('endpoint')
                ->lockForUpdate()
                ->find($this->deliveryId);

            if ($delivery === null || $delivery->status === WebhookDeliveryStatus::Delivered) {
                return null;
            }

            if (! $delivery->endpoint->is_active) {
                $delivery->update([
                    'status' => WebhookDeliveryStatus::Failed,
                    'last_error' => 'Endpoint was inactive when delivery was attempted.',
                    'next_attempt_at' => null,
                ]);

                return null;
            }

            $delivery->increment('attempts');

            return $delivery->fresh('endpoint');
        });

        if ($delivery === null) {
            return;
        }

        try {
            $pin = app(OutboundDestination::class)->validate($delivery->endpoint->url);
            $body = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
            $timestamp = (string) now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, $delivery->endpoint->secret);

            $response = Http::acceptJson()
                ->timeout(10)
                ->withoutRedirecting()
                ->withOptions($pin->httpOptions())
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-ApproveDesk-Event' => $delivery->event,
                    'X-ApproveDesk-Delivery' => $delivery->event_id,
                    'X-ApproveDesk-Timestamp' => $timestamp,
                    'X-ApproveDesk-Signature' => 'sha256='.$signature,
                ])
                ->withBody($body, 'application/json')
                ->send('POST', $pin->url);

        } catch (InvalidArgumentException) {
            $this->failDelivery($delivery, 'The destination is not allowed.');
        } catch (ConnectionException $exception) {
            $this->failDelivery($delivery, $exception->getMessage());
        } catch (Throwable $exception) {
            $this->failDelivery($delivery, $exception->getMessage());
        }

        if (! $response->successful()) {
            $this->failDelivery($delivery, 'Endpoint responded with HTTP '.$response->status().'.', $response->status(), $response->body());
        }

        $delivery->update([
            'status' => WebhookDeliveryStatus::Delivered,
            'response_status' => $response->status(),
            'response_body' => Str::limit($response->body(), 2000, ''),
            'last_error' => null,
            'delivered_at' => now(),
            'next_attempt_at' => null,
        ]);
    }

    /**
     * The queue gave up (timeout, crash, or out of tries): a delivery still
     * marked Pending would wait forever, so mark it Failed (RV-LAR-07).
     */
    public function failed(?Throwable $exception): void
    {
        WebhookDelivery::query()
            ->whereKey($this->deliveryId)
            ->where('status', WebhookDeliveryStatus::Pending->value)
            ->update([
                'status' => WebhookDeliveryStatus::Failed->value,
                'last_error' => Str::limit('The delivery job stopped: '.($exception?->getMessage() ?? 'unknown error'), 2000, ''),
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function failDelivery(
        WebhookDelivery $delivery,
        string $error,
        ?int $responseStatus = null,
        ?string $responseBody = null,
    ): never {
        $finalAttempt = $delivery->attempts >= $this->tries;

        $delivery->update([
            'status' => $finalAttempt ? WebhookDeliveryStatus::Failed : WebhookDeliveryStatus::Pending,
            'response_status' => $responseStatus,
            'response_body' => $responseBody === null ? null : Str::limit($responseBody, 2000, ''),
            'last_error' => Str::limit($error, 2000, ''),
            'next_attempt_at' => $finalAttempt ? null : now()->addSeconds($this->backoff[$delivery->attempts - 1] ?? 3600),
        ]);

        throw new RuntimeException('Webhook delivery failed: '.$error);
    }
}
