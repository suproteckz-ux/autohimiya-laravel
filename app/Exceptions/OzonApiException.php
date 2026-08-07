<?php

namespace App\Exceptions;

use RuntimeException;

class OzonApiException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, public readonly ?string $errorCode = null, public readonly bool $retryable = false)
    {
        parent::__construct($message);
    }
}
