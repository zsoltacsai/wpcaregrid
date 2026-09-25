<?php

namespace App\AdminAccess;

/** An admin access request was refused. The code maps to a customer message. */
class AdminAccessRefused extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
