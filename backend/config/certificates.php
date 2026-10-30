<?php

/*
 * Project 21 automation settings. Everything that an operator may need to tune
 * lives here and is read from .env, so no code change is needed.
 */
return [

    'generator' => [
        // Base URL of the Certificate Generator Service, e.g. http://127.0.0.1:8081
        'url' => env('GENERATOR_URL', 'http://127.0.0.1:8081'),
        // Shared secret sent as X-Service-Key. Never commit the real value.
        'key' => env('GENERATOR_SERVICE_KEY'),
        // Seconds to wait for the generator before treating the call as a transient timeout.
        'timeout' => (int) env('GENERATOR_TIMEOUT', 10),
    ],

    'retry' => [
        // Total attempts per generation job (first try + retries).
        'max_attempts' => (int) env('CERT_MAX_ATTEMPTS', 3),
        // Wait in seconds before retry 1, retry 2, ... (exponential-style backoff).
        'backoff' => array_map('intval', explode(',', env('CERT_RETRY_BACKOFF', '10,30'))),
        // Up to this many random extra seconds are added so retries do not line up (jitter).
        'jitter' => (int) env('CERT_RETRY_JITTER', 5),
    ],

    'sweeper' => [
        // Items stuck in queued/generating/retrying longer than this are re-dispatched once,
        // then sent to needs_review if they are still stuck.
        'stuck_after_minutes' => (int) env('CERT_STUCK_AFTER_MINUTES', 15),
    ],

    // Prefix for certificate numbers, e.g. ULS-2026-000042
    'number_prefix' => env('CERT_NUMBER_PREFIX', 'ULS'),

];
