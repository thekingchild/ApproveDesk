<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Replays a stored response when the same user resends Idempotency-Key
 * on a mutating API request. A conflicting body returns 409.
 *
 * The key is claimed by inserting its row (unique on user + key) before the
 * request runs, so two concurrent requests cannot both pass a "not seen yet"
 * check and both run (RV-BUG-27). The loser gets 409 while the winner is
 * still working, then the stored response once it has finished.
 */
class HandleApiIdempotency
{
    /** A claim with no response after this long is treated as abandoned (killed worker, fatal error). */
    public const STALE_AFTER_MINUTES = 5;

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));
        $user = $request->user();

        if ($key === '' || $user === null) {
            return $next($request);
        }

        if (mb_strlen($key) > 255) {
            return response()->json(['message' => 'Idempotency-Key must be 255 characters or fewer.'], 422);
        }

        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $claim = $this->claim((int) $user->getAuthIdentifier(), $key, $request, $hash);

        if (! $claim instanceof ApiIdempotencyKey) {
            return $claim;
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $exception) {
            $claim->delete();

            throw $exception;
        }

        if ($response->getStatusCode() >= 500) {
            // Let the client retry a server error with the same key.
            $claim->delete();

            return $response;
        }

        $claim->forceFill([
            'status_code' => $response->getStatusCode(),
            'response_body' => json_decode($response->getContent() ?: 'null', true),
        ])->save();

        return $response;
    }

    /**
     * Returns the claimed row when this request may run, or the response to
     * send instead (a replay, a conflict, or "still in progress").
     */
    private function claim(int $userId, string $key, Request $request, string $hash): ApiIdempotencyKey|Response
    {
        try {
            return ApiIdempotencyKey::query()->create([
                'user_id' => $userId,
                'key' => $key,
                'method' => $request->method(),
                'path' => mb_substr('/'.$request->path(), 0, 255),
                'request_hash' => $hash,
                'status_code' => null,
                'response_body' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request already holds this key.
        }

        $existing = ApiIdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('key', $key)
            ->first();

        if ($existing === null) {
            // The holder failed and released the key between our insert and this read.
            return response()->json(['message' => 'A request with this Idempotency-Key is still being processed. Retry shortly.'], 409, ['Retry-After' => '1']);
        }

        if ($existing->request_hash !== $hash) {
            return response()->json([
                'message' => 'Idempotency-Key was reused with a different request.',
            ], 409);
        }

        if ($existing->status_code !== null) {
            return response()->json($existing->response_body, $existing->status_code);
        }

        // Take over an abandoned claim; the conditional update lets only one request win it.
        $takenOver = ApiIdempotencyKey::query()
            ->whereKey($existing->getKey())
            ->whereNull('status_code')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->update(['updated_at' => now()]);

        if ($takenOver === 1) {
            return $existing->refresh();
        }

        return response()->json([
            'message' => 'A request with this Idempotency-Key is still being processed. Retry shortly.',
        ], 409, ['Retry-After' => '1']);
    }
}
