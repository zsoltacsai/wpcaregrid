<?php

namespace App\Connector;

/** An update was not queued. The code maps to operator wording. */
class UpdateRefused extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
