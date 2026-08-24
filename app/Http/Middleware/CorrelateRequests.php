<?php

namespace App\Http\Middleware;

use App\Support\CorrelationId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

final class CorrelateRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = CorrelationId::accept($request->headers->get(CorrelationId::HEADER));
        CorrelationId::set($id);

        Log::shareContext([
            'correlation_id' => $id,
            'user_id' => $request->user()?->getAuthIdentifier(),
            'path' => $request->path(),
        ]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(CorrelationId::HEADER, $id);

        return $response;
    }
}
