<?php

declare(strict_types=1);

/*
 * Week 14 – receiving side of the secure exchange.
 *
 * Every POST /generate must carry these headers (set by the Laravel sender):
 *
 *   X-Key-Id         which key pair was used (lets us rotate keys)
 *   X-Timestamp      Unix seconds when the request was signed
 *   X-Nonce          random, single-use value (stops replays)
 *   X-Content-SHA256 hex SHA-256 of the raw body
 *   X-Signature      hex HMAC-SHA256 over the canonical string below
 *
 * Canonical string (one value per line):
 *   CERTQ-HMAC-SHA256 / METHOD / PATH / TIMESTAMP / NONCE / KEY_ID / BODY_SHA256
 *
 * The body carries the personal fields only inside "protected": an
 * XChaCha20-Poly1305 (libsodium) ciphertext whose associated data binds it to
 * job_key + certificate_type + purpose, so it cannot be moved to another job.
 *
 * Checks run in this order and stop at the first failure:
 *   1. key ID known            -> 401 UNKNOWN_KEY_ID
 *   2. signature matches       -> 401 BAD_SIGNATURE   (constant-time compare)
 *   3. timestamp within 5 min  -> 401 REQUEST_EXPIRED
 *   4. nonce never seen        -> 409 REPLAY_DETECTED (only stored after 2+3 pass,
 *                                 so an attacker cannot "burn" nonces)
 *   5. ciphertext decrypts     -> 400 DECRYPTION_FAILED
 */

final class RejectedRequest extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $reason,
        string $message,
        public readonly ?string $keyId = null,
    ) {
        parent::__construct($message);
    }
}

/** Remembers used nonces in a small locked JSON file (enough for one mock service). */
final class NonceStore
{
    public function __construct(private string $file, private int $ttlSeconds) {}

    /** Returns false if the nonce was already used; otherwise records it and returns true. */
    public function useOnce(string $keyId, string $nonce, int $now): bool
    {
        $fp = fopen($this->file, 'c+');
        flock($fp, LOCK_EX);
        try {
            $seen = json_decode(stream_get_contents($fp) ?: '{}', true) ?: [];
            // Forget nonces older than the TTL: their timestamps are expired anyway.
            $seen = array_filter($seen, fn (int $at) => $at > $now - $this->ttlSeconds);

            $id = $keyId . ':' . $nonce;
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = $now;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($seen));

            return true;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}

final class SecureReceiver
{
    public const ALGORITHM = 'XChaCha20-Poly1305';
    public const SCHEME = 'CERTQ-HMAC-SHA256';

    /**
     * @param array<string, array{enc: string, sign: string}> $keys raw binary keys by key ID
     */
    public function __construct(
        private array $keys,
        private NonceStore $nonces,
        private int $maxSkewSeconds = 300,
    ) {}

    /**
     * Reads accepted keys from config:
     *   GENERATOR_ACCEPTED_KEY_IDS=k2026a,k2026b
     *   GENERATOR_ENCRYPTION_KEY_k2026a=base64:...
     *   GENERATOR_SIGNING_KEY_k2026a=base64:...
     *
     * @return array<string, array{enc: string, sign: string}>
     */
    public static function keysFromConfig(array $config): array
    {
        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', $config['GENERATOR_ACCEPTED_KEY_IDS'] ?? ''))) as $kid) {
            if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $kid)) {
                continue;
            }
            $enc = self::decodeKey($config["GENERATOR_ENCRYPTION_KEY_{$kid}"] ?? '');
            $sign = self::decodeKey($config["GENERATOR_SIGNING_KEY_{$kid}"] ?? '');
            // Skip broken entries instead of accepting weak or reused keys.
            if (strlen($enc) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES || strlen($sign) < 32 || hash_equals($enc, $sign)) {
                continue;
            }
            $keys[$kid] = ['enc' => $enc, 'sign' => $sign];
        }

        return $keys;
    }

    private static function decodeKey(string $value): string
    {
        if (!str_starts_with($value, 'base64:')) {
            return '';
        }
        $raw = base64_decode(substr($value, 7), true);

        return $raw === false ? '' : $raw;
    }

    /**
     * Verifies and decrypts one request.
     *
     * @param array<string, string> $headers lower-case header names
     * @return array<string, string> the payload with requester_name and requester_id_number decrypted
     */
    public function open(string $method, string $path, array $headers, string $rawBody, ?int $now = null): array
    {
        $now ??= time();
        $keyId = $headers['x-key-id'] ?? '';
        $timestamp = $headers['x-timestamp'] ?? '';
        $nonce = $headers['x-nonce'] ?? '';
        $signature = strtolower($headers['x-signature'] ?? '');
        $declaredHash = strtolower($headers['x-content-sha256'] ?? '');

        if ($keyId === '' || $timestamp === '' || $nonce === '' || $signature === '') {
            throw new RejectedRequest(401, 'MISSING_SIGNATURE', 'Request is not signed.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            throw new RejectedRequest(401, 'UNKNOWN_KEY_ID', 'Unknown key ID.');
        }
        $safeKid = $keyId;

        // 1. Key ID must be one we currently accept (removed keys are revoked).
        if (!isset($this->keys[$keyId])) {
            throw new RejectedRequest(401, 'UNKNOWN_KEY_ID', 'Unknown key ID.', $safeKid);
        }
        $key = $this->keys[$keyId];

        // 2. Signature. We hash the body ourselves; the declared hash must also match.
        $bodyHash = hash('sha256', $rawBody);
        if (!preg_match('/^\d{1,12}$/', $timestamp) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce)
            || ($declaredHash !== '' && !hash_equals($bodyHash, $declaredHash))) {
            throw new RejectedRequest(401, 'BAD_SIGNATURE', 'Signature check failed.', $safeKid);
        }
        $canonical = implode("\n", [self::SCHEME, strtoupper($method), $path, $timestamp, $nonce, $keyId, $bodyHash]);
        $expected = hash_hmac('sha256', $canonical, $key['sign']);
        if (!hash_equals($expected, $signature)) {
            throw new RejectedRequest(401, 'BAD_SIGNATURE', 'Signature check failed.', $safeKid);
        }

        // 3. Freshness: reject anything signed more than 5 minutes ago (or in the future).
        if (abs($now - (int) $timestamp) > $this->maxSkewSeconds) {
            throw new RejectedRequest(401, 'REQUEST_EXPIRED', 'Request timestamp is outside the allowed window.', $safeKid);
        }

        // 4. Single use. TTL is twice the window so a nonce is remembered for as long as it could be valid.
        if (!$this->nonces->useOnce($keyId, $nonce, $now)) {
            throw new RejectedRequest(409, 'REPLAY_DETECTED', 'This request was already received.', $safeKid);
        }

        // 5. Decrypt the protected fields (authenticated: any change makes this fail).
        $input = json_decode($rawBody, true);
        if (!is_array($input)) {
            throw new RejectedRequest(400, 'BAD_REQUEST', 'Body must be JSON.', $safeKid);
        }
        $protected = $input['protected'] ?? null;
        foreach (['job_key', 'certificate_type', 'purpose'] as $field) {
            if (!isset($input[$field]) || !is_string($input[$field])) {
                throw new RejectedRequest(422, 'VALIDATION_FAILED', 'Invalid input.', $safeKid);
            }
        }
        if (!is_array($protected) || ($protected['alg'] ?? '') !== self::ALGORITHM || ($protected['kid'] ?? '') !== $keyId
            || !is_string($protected['nonce'] ?? null) || !is_string($protected['ciphertext'] ?? null)) {
            throw new RejectedRequest(400, 'DECRYPTION_FAILED', 'Protected fields could not be opened.', $safeKid);
        }

        $aeadNonce = base64_decode($protected['nonce'], true);
        $ciphertext = base64_decode($protected['ciphertext'], true);
        $plaintext = false;
        if ($aeadNonce !== false && $ciphertext !== false
            && strlen($aeadNonce) === SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                self::associatedData($input['job_key'], $input['certificate_type'], $input['purpose']),
                $aeadNonce,
                $key['enc'],
            );
        }
        $fields = is_string($plaintext) ? json_decode($plaintext, true) : null;
        if (!is_array($fields)) {
            throw new RejectedRequest(400, 'DECRYPTION_FAILED', 'Protected fields could not be opened.', $safeKid);
        }

        return [
            'job_key' => $input['job_key'],
            'certificate_type' => $input['certificate_type'],
            'purpose' => $input['purpose'],
            'requester_name' => (string) ($fields['requester_name'] ?? ''),
            'requester_id_number' => (string) ($fields['requester_id_number'] ?? ''),
        ];
    }

    /** Must match App\Security\PayloadEncryptor::associatedData() in the backend. */
    public static function associatedData(string $jobKey, string $certificateType, string $purpose): string
    {
        return json_encode(
            ['v' => 1, 'job_key' => $jobKey, 'certificate_type' => $certificateType, 'purpose' => $purpose],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
