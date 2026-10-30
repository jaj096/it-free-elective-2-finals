<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A failed call to the Certificate Generator Service.
 *
 * $transient = true  -> worth retrying (timeout, 5xx, 429, connection refused)
 * $transient = false -> retrying cannot help (4xx, bad input, bad credentials)
 *
 * $safeReason is a plain-language reason that may be shown to staff; it never contains secrets.
 */
class GeneratorException extends RuntimeException
{
    public function __construct(
        public readonly bool $transient,
        public readonly string $safeReason,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($safeReason);
    }

    public static function transient(string $reason, ?int $status = null): self
    {
        return new self(true, $reason, $status);
    }

    public static function permanent(string $reason, ?int $status = null): self
    {
        return new self(false, $reason, $status);
    }
}
