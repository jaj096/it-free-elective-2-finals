<?php

namespace App\Security;

/**
 * Deliberate faults for the Week 14 failure-injection tests
 * (php artisan generator:security-test). Never used by the real job.
 */
enum Tamper: string
{
    /** Flip one byte of the ciphertext, then sign the altered body (simulates an attacker who has the signing key only). */
    case AlteredCiphertext = 'altered-ciphertext';

    /** Sign with a key the receiver does not have. */
    case BadSignature = 'bad-signature';

    /** Sign correctly, but with a timestamp 10 minutes in the past. */
    case ExpiredTimestamp = 'expired-timestamp';

    /** Use a key ID the receiver does not accept (e.g. a revoked key). */
    case WrongKeyId = 'wrong-key-id';
}
