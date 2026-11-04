<?php

use App\Exceptions\InvalidStateTransition;
use App\Http\ApiError;
use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\RequireHttps;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, so even 404s for unknown URLs carry a correlation ID.
        $middleware->prepend(AssignCorrelationId::class);
        $middleware->alias(['role' => EnsureRole::class]);

        // Week 14: only the local TLS proxy (Caddy) may tell us the original request was HTTPS.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->prependToGroup('api', RequireHttps::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $isApi = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($isApi);

        // Every API error uses the same shape: { "error": { code, message, fields?, correlation_id } }.
        // Diagnostic detail stays in protected logs, never in the response.
        $exceptions->render(function (Throwable $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiError::response(422, 'VALIDATION_FAILED', 'Some fields are invalid.', $e->errors()),
                // An expired token gets its own code so the client can say why (it is still a 401).
                $e instanceof AuthenticationException => $request->bearerToken()
                    && PersonalAccessToken::findToken($request->bearerToken())?->expires_at?->isPast()
                    ? ApiError::response(401, 'TOKEN_EXPIRED', 'Your session has expired. Please sign in again.')
                    : ApiError::response(401, 'UNAUTHENTICATED', 'Please sign in again.'),
                $e instanceof AuthorizationException,
                $e instanceof AccessDeniedHttpException => ApiError::response(403, 'FORBIDDEN', "You don't have access to this action."),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiError::response(404, 'NOT_FOUND', 'The requested item was not found.'),
                $e instanceof MethodNotAllowedHttpException => ApiError::response(405, 'METHOD_NOT_ALLOWED', 'This method is not allowed here.'),
                $e instanceof InvalidStateTransition => ApiError::response(409, 'INVALID_STATE', $e->getMessage()),
                $e instanceof ThrottleRequestsException => ApiError::response(429, 'TOO_MANY_REQUESTS', 'Too many attempts. Please wait a minute and try again.'),
                $e instanceof BadRequestHttpException => ApiError::response(400, 'BAD_REQUEST', 'The request could not be read.'),
                // abort(...) calls from controllers keep their own safe message.
                $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => ApiError::response(
                    $e->getStatusCode(),
                    match ($e->getStatusCode()) {
                        400 => 'BAD_REQUEST',
                        403 => 'FORBIDDEN',
                        404 => 'NOT_FOUND',
                        409 => 'CONFLICT',
                        422 => 'VALIDATION_FAILED',
                        default => 'HTTP_'.$e->getStatusCode(),
                    },
                    $e->getMessage() ?: 'Request failed.',
                ),
                default => ApiError::response(500, 'SERVER_ERROR', 'Something went wrong. Quote the reference when asking for help.'),
            };
        });
    })->create();
