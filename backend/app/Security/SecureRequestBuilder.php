<?php

namespace App\Security;

/**
 * Builds the protected, signed POST /generate request.
 *
 * Wire format (the only data that leaves the backend):
 * {
 *   "v": 1,
 *   "job_key": "uuid",                 not personal: needed for idempotency
 *   "certificate_type": "enrollment",  printed, not personal
 *   "purpose": "Scholarship ...",      printed; authenticated as associated data
 *   "protected": {                     requester_name + requester_id_number, encrypted
 *     "alg": "XChaCha20-Poly1305", "kid": "k2026a", "nonce": "...", "ciphertext": "..."
 *   }
 * }
 * Not sent: email, user ID, role, password, request ID, approver, anything not printed.
 */
final class SecureRequestBuilder
{
    public const METHOD = 'POST';

    public const PATH = '/generate';

    /** Exactly the fields the certificate prints. */
    public const PRINTED_FIELDS = ['job_key', 'certificate_type', 'purpose', 'requester_name', 'requester_id_number'];

    /** The personal fields that are encrypted. */
    public const PROTECTED_FIELDS = ['requester_name', 'requester_id_number'];

    /**
     * @param  array<string, string>  $fields  the PRINTED_FIELDS
     */
    public function build(array $fields, ?Tamper $tamper = null): SignedRequest
    {
        $keys = GeneratorKeyring::fromConfig();

        if ($tamper === Tamper::WrongKeyId) {
            $keys = new GeneratorKeyring('k-revoked', $keys->encryptionKey, $keys->signingKey);
        }

        $associatedData = PayloadEncryptor::associatedData($fields['job_key'], $fields['certificate_type'], $fields['purpose']);
        $protected = PayloadEncryptor::seal(
            array_intersect_key($fields, array_flip(self::PROTECTED_FIELDS)),
            $associatedData,
            $keys,
        );

        if ($tamper === Tamper::AlteredCiphertext) {
            $bytes = base64_decode($protected['ciphertext']);
            $bytes[0] = chr(ord($bytes[0]) ^ 0x01); // flip one bit
            $protected['ciphertext'] = base64_encode($bytes);
        }

        $body = json_encode([
            'v' => 1,
            'job_key' => $fields['job_key'],
            'certificate_type' => $fields['certificate_type'],
            'purpose' => $fields['purpose'],
            'protected' => $protected,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $timestamp = $tamper === Tamper::ExpiredTimestamp ? time() - 600 : null;
        $headers = RequestSigner::headers(self::METHOD, self::PATH, $body, $keys, $timestamp);

        if ($tamper === Tamper::BadSignature) {
            $attacker = new GeneratorKeyring($keys->keyId, $keys->encryptionKey, random_bytes(32));
            $headers['X-Signature'] = RequestSigner::headers(self::METHOD, self::PATH, $body, $attacker,
                (int) $headers['X-Timestamp'], $headers['X-Nonce'])['X-Signature'];
        }

        return new SignedRequest(self::METHOD, self::PATH, $headers, $body);
    }
}
