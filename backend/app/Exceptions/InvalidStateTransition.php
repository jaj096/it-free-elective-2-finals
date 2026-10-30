<?php

namespace App\Exceptions;

use App\Enums\RequestStatus;
use RuntimeException;

/** Thrown when an action is not allowed in the request's current status (HTTP 409). */
class InvalidStateTransition extends RuntimeException
{
    public function __construct(public readonly RequestStatus $from, public readonly RequestStatus $to)
    {
        parent::__construct("This action is not allowed while the request is {$from->label()}.");
    }
}
