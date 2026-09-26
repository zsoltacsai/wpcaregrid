<?php

namespace App\Security;

/** A security action was not started. The code maps to operator wording. */
class SecurityRefused extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message ?: $errorCode);
    }
}
