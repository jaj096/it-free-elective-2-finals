<?php

/*
 * The Flutter Web client runs on a different port/origin, so the browser needs CORS.
 * Set CORS_ALLOWED_ORIGINS to the exact Flutter origin(s), comma-separated.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:5000'))),

    // Allows any localhost port during development (flutter run picks a random port).
    'allowed_origins_patterns' => env('APP_ENV') === 'local' ? ['#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#'] : [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Correlation-ID'],

    // Lets the Flutter app read the correlation ID and the download filename.
    'exposed_headers' => ['X-Correlation-ID', 'Content-Disposition', 'X-Content-SHA256'],

    'max_age' => 600,

    // Bearer tokens are used, not cookies.
    'supports_credentials' => false,

];
