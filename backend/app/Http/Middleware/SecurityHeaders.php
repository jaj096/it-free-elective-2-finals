<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Week 15 (W4): hardening headers on every API response, including errors and PDF downloads.
 *
 * - Cache-Control: no-store   responses carry personal data and certificates; no browser or proxy copy
 * - Content-Security-Policy   the API never serves pages, so nothing may load or frame its responses
 * - X-Frame-Options: DENY     same, for older browsers
 * - Referrer-Policy           never leak API URLs (which contain IDs) to other sites
 * - X-Content-Type-Options    also set by Caddy; set here too so it holds without the proxy
 * - X-Powered-By removed      PHP added "X-Powered-By: PHP/8.5.x", which tells attackers the exact version
 *
 * Done in Laravel (not only in Caddy) so the feature tests can prove it.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only the API. The Laravel welcome page at "/" would break under default-src 'none'.
        if (! $request->is('api/*')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By'); // added by PHP itself (expose_php), not by Laravel
        }

        return $response;
    }
}
