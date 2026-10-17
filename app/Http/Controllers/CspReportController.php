<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Content Security Policy violation reports (ADR 0005 step 2). Public
 * and unauthenticated by nature, so: throttled per IP (routes), capped in size,
 * a few reports per request, query strings stripped (signed links carry their
 * signature there), written to the csp log channel only. No database writes,
 * no mail.
 */
final class CspReportController
{
    public const MAX_BYTES = 8192;

    public const MAX_REPORTS = 10;

    public function __invoke(Request $request): Response
    {
        $body = (string) $request->getContent();

        if (strlen($body) > self::MAX_BYTES) {
            return response('', 413);
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return response('', 400);
        }

        foreach (array_slice($this->reports($decoded), 0, self::MAX_REPORTS) as $report) {
            Log::channel('csp')->info('csp.violation', $report);
        }

        return response('', 204);
    }

    /**
     * Both the legacy report-uri format ({"csp-report": {...}}) and the
     * Reporting API format ([{"type": "csp-violation", "body": {...}}]).
     *
     * @param  array<mixed>  $decoded
     * @return list<array<string, string|int|null>>
     */
    private function reports(array $decoded): array
    {
        if (isset($decoded['csp-report']) && is_array($decoded['csp-report'])) {
            return [$this->normalise($decoded['csp-report'])];
        }

        $reports = [];
        foreach ($decoded as $entry) {
            if (is_array($entry) && ($entry['type'] ?? null) === 'csp-violation' && is_array($entry['body'] ?? null)) {
                $reports[] = $this->normalise($entry['body']);
            }
        }

        return $reports;
    }

    /**
     * @param  array<mixed>  $raw
     * @return array<string, string|int|null>
     */
    private function normalise(array $raw): array
    {
        $pick = fn (string ...$keys): mixed => collect($keys)->map(fn (string $key) => $raw[$key] ?? null)->first(fn ($value) => $value !== null);

        return [
            'document' => $this->url($pick('document-uri', 'documentURL')),
            'directive' => $this->text($pick('effective-directive', 'effectiveDirective', 'violated-directive')),
            'blocked' => $this->url($pick('blocked-uri', 'blockedURL')),
            'disposition' => $this->text($pick('disposition')),
            'source' => $this->url($pick('source-file', 'sourceFile')),
            'line' => is_numeric($pick('line-number', 'lineNumber')) ? (int) $pick('line-number', 'lineNumber') : null,
            'sample' => $this->text($pick('script-sample', 'sample'), 120),
        ];
    }

    private function url(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Str::limit(Str::before($value, '?'), 300, '');
    }

    private function text(mixed $value, int $limit = 100): ?string
    {
        return is_string($value) && $value !== '' ? Str::limit($value, $limit, '') : null;
    }
}
