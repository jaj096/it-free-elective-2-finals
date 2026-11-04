<?php

namespace App\Security;

/** A ready-to-send request: exact body bytes plus the security headers that sign them. */
final class SignedRequest
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly string $body,
    ) {}
}
