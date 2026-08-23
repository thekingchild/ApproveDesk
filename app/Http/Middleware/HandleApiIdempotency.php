<?php

namespace App\Http\Middleware;

use App\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replays a stored response when the same user resends Idempotency-Key
 * on a mutating API request. A conflicting body returns 409.
 */
class HandleApiIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));

        if ($key === '' || $request->user() === null) {
            return $next($request);
        }

        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        $existing = ApiIdempotencyKey::query()
            ->where('user_id', $request->user()->id)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $hash) {
                return response()->json([
                    'message' => 'Idempotency-Key was reused with a different request.',
                ], 409);
            }

            return response()->json($existing->response_body, $existing->status_code);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            $decoded = json_decode($response->getContent() ?: 'null', true);

            ApiIdempotencyKey::query()->create([
                'user_id' => $request->user()->id,
                'key' => $key,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'request_hash' => $hash,
                'status_code' => $response->getStatusCode(),
                'response_body' => $decoded,
            ]);
        }

        return $response;
    }
}
