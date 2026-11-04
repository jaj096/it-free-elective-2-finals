<?php

namespace App\Security;

/**
 * Authenticated encryption of the personal fields with libsodium XChaCha20-Poly1305 (IETF).
 *
 *  - Confidentiality: the name and ID number are unreadable without the encryption key.
 *  - Integrity: Poly1305 adds a tag; changing one bit of the ciphertext makes decryption fail.
 *  - Binding: the "associated data" (job_key, certificate_type, purpose) is authenticated but
 *    not encrypted, so a valid ciphertext cannot be copied into a different job.
 *  - A fresh random 24-byte nonce is used for every message (XChaCha's large nonce makes
 *    random nonces safe; never reuse a nonce with the same key).
 */
final class PayloadEncryptor
{
    public const ALGORITHM = 'XChaCha20-Poly1305';

    /**
     * @param  array<string, string>  $sensitive
     * @return array{alg: string, kid: string, nonce: string, ciphertext: string}
     */
    public static function seal(array $sensitive, string $associatedData, GeneratorKeyring $keys): array
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            json_encode($sensitive, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $associatedData,
            $nonce,
            $keys->encryptionKey,
        );

        return [
            'alg' => self::ALGORITHM,
            'kid' => $keys->keyId,
            'nonce' => base64_encode($nonce),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /** Opens a sealed block. Returns null if it was altered or the key is wrong. Used by tests. */
    public static function open(array $sealed, string $associatedData, GeneratorKeyring $keys): ?array
    {
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            (string) base64_decode((string) ($sealed['ciphertext'] ?? ''), true),
            $associatedData,
            (string) base64_decode((string) ($sealed['nonce'] ?? ''), true),
            $keys->encryptionKey,
        );

        return is_string($plain) ? json_decode($plain, true) : null;
    }

    /** Must match SecureReceiver::associatedData() in generator-service. */
    public static function associatedData(string $jobKey, string $certificateType, string $purpose): string
    {
        return json_encode(
            ['v' => 1, 'job_key' => $jobKey, 'certificate_type' => $certificateType, 'purpose' => $purpose],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
