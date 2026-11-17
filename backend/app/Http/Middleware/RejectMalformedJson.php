<?php

namespace App\Http\Middleware;

use App\Http\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Week 15 (W3): a body sent as JSON that is not valid JSON gets 400 MALFORMED_JSON.
 *
 * Before, Laravel quietly read a broken body as "no fields", so the client got 422 "Choose a
 * certificate type" for a request that DID contain one. That blamed the user for a client bug
 * and made the defect hard to find. An empty body is still allowed (it is not malformed).
 */
class RejectMalformedJson
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isJson() && trim($request->getContent()) !== '') {
            json_decode($request->getContent());
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ApiError::response(400, 'MALFORMED_JSON', 'The request body is not valid JSON.');
            }
        }

        return $next($request);
    }
}
