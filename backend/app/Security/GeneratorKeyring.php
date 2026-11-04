<?php

namespace App\Security;

use App\Exceptions\GeneratorException;

/**
 * The sender's current key pair for talking to the generator, read only from .env:
 *
 *   GENERATOR_KEY_ID=k2026a                 sent as X-Key-Id so the receiver can pick the right keys
 *   GENERATOR_ENCRYPTION_KEY=base64:...     32 bytes, XChaCha20-Poly1305 (confidentiality + integrity)
 *   GENERATOR_SIGNING_KEY=base64:...        >= 32 bytes, HMAC-SHA256 (authenticity of the whole request)
 *
 * Two separate keys: a key is used for one purpose only, so a weakness or leak in one
 * use does not automatically break the other.
 */
final class GeneratorKeyring
{
    public function __construct(
        public readonly string $keyId,
        #[\SensitiveParameter] public readonly string $encryptionKey,
        #[\SensitiveParameter] public readonly string $signingKey,
    ) {}

    /** @throws GeneratorException (permanent) when keys are missing or invalid. Messages never contain key material. */
    public static function fromConfig(): self
    {
        $keyId = (string) config('certificates.generator.key_id');
        $enc = self::decode((string) config('certificates.generator.encryption_key'));
        $sign = self::decode((string) config('certificates.generator.signing_key'));

        if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            throw GeneratorException::permanent('Generator key ID is not configured. Check GENERATOR_KEY_ID.');
        }
        if (strlen($enc) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw GeneratorException::permanent('Generator encryption key must be base64: + 32 bytes. Check GENERATOR_ENCRYPTION_KEY.');
        }
        if (strlen($sign) < 32) {
            throw GeneratorException::permanent('Generator signing key must be base64: + at least 32 bytes. Check GENERATOR_SIGNING_KEY.');
        }
        if (hash_equals($enc, $sign)) {
            throw GeneratorException::permanent('Generator encryption and signing keys must be different.');
        }

        return new self($keyId, $enc, $sign);
    }

    private static function decode(string $value): string
    {
        if (! str_starts_with($value, 'base64:')) {
            return '';
        }

        return base64_decode(substr($value, 7), true) ?: '';
    }

    /** Never print keys in var_dump/logs by accident. */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId, 'encryptionKey' => '[hidden]', 'signingKey' => '[hidden]'];
    }
}
