<?php

/*
 * Project 21 automation settings. Everything that an operator may need to tune
 * lives here and is read from .env, so no code change is needed.
 */
return [

    'generator' => [
        // Base URL of the Certificate Generator Service. Week 14: HTTPS through Caddy, e.g. https://localhost:8444
        'url' => env('GENERATOR_URL', 'https://localhost:8444'),
        // Seconds to wait for the generator before treating the call as a transient timeout.
        'timeout' => (int) env('GENERATOR_TIMEOUT', 10),

        // --- Week 14: secure exchange (see app/Security) ---
        // Refuse to send personal data unless GENERATOR_URL is https://.
        'require_https' => filter_var(env('GENERATOR_REQUIRE_HTTPS', true), FILTER_VALIDATE_BOOL),
        // PEM file of the local development CA used to verify the generator's certificate.
        // Empty = use the system trust store. TLS verification is never disabled.
        'ca_bundle' => env('GENERATOR_CA_BUNDLE'),
        // Current key pair. Sent as X-Key-Id; the receiver may accept several IDs during rotation.
        'key_id' => env('GENERATOR_KEY_ID'),
        // XChaCha20-Poly1305 key for requester_name + requester_id_number (base64: + 32 bytes).
        'encryption_key' => env('GENERATOR_ENCRYPTION_KEY'),
        // HMAC-SHA256 key for request signatures (base64: + 32 bytes). Must differ from the encryption key.
        'signing_key' => env('GENERATOR_SIGNING_KEY'),
        // Plain-HTTP address of the generator, used ONLY by generator:security-test to prove it is refused.
        'plain_http_url' => env('GENERATOR_PLAIN_HTTP_URL', 'http://127.0.0.1:8081'),
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
