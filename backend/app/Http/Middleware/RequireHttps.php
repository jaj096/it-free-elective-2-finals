<?php

namespace App\Http\Middleware;

use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Week 14: the API only answers over HTTPS when APP_REQUIRE_HTTPS=true.
 *
 * Locally, Caddy terminates TLS and forwards to `php artisan serve` with
 * X-Forwarded-Proto: https. That header is only trusted from 127.0.0.1 / ::1
 * (trustProxies in bootstrap/app.php), so $request->secure() cannot be faked remotely.
 * We refuse instead of redirecting: a redirect would mean the token was already sent in clear.
 */
class RequireHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.require_https') && ! $request->secure()) {
            return ApiError::response(403, 'HTTPS_REQUIRED', 'Use the secure (https://) address.');
        }

        return $next($request);
    }
}
