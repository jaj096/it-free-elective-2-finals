<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;

/** Builds the one error format every API error uses. */
class ApiError
{
    /**
     * @param  array<string, array<int, string>>|null  $fields
     */
    public static function response(int $status, string $code, string $message, ?array $fields = null): JsonResponse
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields) {
            $error['fields'] = $fields;
        }
        $error['correlation_id'] = Context::get('correlation_id');

        return new JsonResponse(['error' => $error], $status);
    }
}
