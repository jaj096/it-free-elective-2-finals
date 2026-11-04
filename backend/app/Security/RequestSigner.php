<?php

namespace App\Security;

/**
 * HMAC-SHA256 request signing (sender side). The receiver recomputes the same value.
 *
 * Signed string, one value per line:
 *   CERTQ-HMAC-SHA256
 *   POST
 *   /generate
 *   1793750400            (X-Timestamp, Unix seconds)
 *   3q2-7w...             (X-Nonce, random, single use)
 *   k2026a                (X-Key-Id)
 *   9f86d08...            (hex SHA-256 of the exact body bytes)
 *
 * Because method, path, time, nonce, key ID and body hash are all inside the MAC,
 * changing any of them (or the body) invalidates the signature.
 */
final class RequestSigner
{
    public const SCHEME = 'CERTQ-HMAC-SHA256';

    public static function canonical(string $method, string $path, string $timestamp, string $nonce, string $keyId, string $bodyHash): string
    {
        return implode("\n", [self::SCHEME, strtoupper($method), $path, $timestamp, $nonce, $keyId, $bodyHash]);
    }

    /**
     * @return array<string, string>
     */
    public static function headers(string $method, string $path, string $body, GeneratorKeyring $keys, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $nonce ??= self::newNonce();
        $bodyHash = hash('sha256', $body);

        return [
            'X-Key-Id' => $keys->keyId,
            'X-Timestamp' => $timestamp,
            'X-Nonce' => $nonce,
            'X-Content-SHA256' => $bodyHash,
            'X-Signature' => hash_hmac('sha256', self::canonical($method, $path, $timestamp, $nonce, $keys->keyId, $bodyHash), $keys->signingKey),
        ];
    }

    /** 128 random bits, URL-safe. */
    public static function newNonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
}
