<?php

namespace App\Services\Integrations;

use App\DTOs\Integrations\ConnectorResult;
use App\Models\Submission;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Shared HTTP transport for connectors. Callers never log credentials.
 */
final class ConnectorHttpClient
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|string  $body
     */
    public function post(string $url, array|string $body, array $headers = [], int $timeout = 10): ConnectorResult
    {
        $encoded = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        $response = Http::acceptJson()
            ->timeout($timeout)
            ->withoutRedirecting()
            ->withHeaders($headers)
            ->withBody($encoded, 'application/json')
            ->send('POST', $url);

        return new ConnectorResult(
            ok: $response->successful(),
            status: $response->status(),
            body: Str::limit($response->body(), 2000, ''),
            error: $response->successful() ? '' : 'HTTP '.$response->status(),
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    public function get(string $url, array $headers = [], int $timeout = 8): ConnectorResult
    {
        $response = Http::acceptJson()
            ->timeout($timeout)
            ->withoutRedirecting()
            ->withHeaders($headers)
            ->get($url);

        return new ConnectorResult(
            ok: $response->successful(),
            status: $response->status(),
            body: Str::limit($response->body(), 2000, ''),
            error: $response->successful() ? '' : 'HTTP '.$response->status(),
        );
    }

    /** @return array<string, mixed> */
    public function submissionPayload(Submission $submission): array
    {
        $submission->loadMissing(['submitter:id,name,email', 'requestTypeVersion.requestType', 'files']);

        return [
            'event' => 'workflow.completed',
            'submission' => [
                'id' => $submission->id,
                'reference' => $submission->reference,
                'title' => $submission->title,
                'status' => $submission->status->value,
                'type' => $submission->type->value,
                'request_type' => $submission->requestTypeVersion?->requestType?->slug,
            ],
            'submitter' => [
                'id' => $submission->submitter?->id,
                'name' => $submission->submitter?->name,
                'email' => $submission->submitter?->email,
            ],
            'files' => $submission->files->map(fn ($file): array => [
                'id' => $file->id,
                'name' => $file->original_name ?? $file->name ?? 'file',
            ])->all(),
        ];
    }

    /** @return array<string, string> */
    public function sign(string $secret, string $body): array
    {
        $timestamp = (string) now()->timestamp;

        return [
            'X-ApproveDesk-Event' => 'workflow.completed',
            'X-ApproveDesk-Timestamp' => $timestamp,
            'X-ApproveDesk-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];
    }
}
