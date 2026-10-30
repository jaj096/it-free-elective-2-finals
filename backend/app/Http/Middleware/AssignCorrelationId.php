<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every API request a correlation ID.
 *
 * Laravel's Context carries the ID into every log line and into queued jobs
 * dispatched during the request, so the API call and the worker share one ID.
 */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->header('X-Correlation-ID', '');
        $id = preg_match('/^[A-Za-z0-9-]{8,64}$/', $incoming)
            ? $incoming
            : 'c-'.Str::lower(Str::random(8));

        Context::add('correlation_id', $id);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $id);

        return $response;
    }
}
