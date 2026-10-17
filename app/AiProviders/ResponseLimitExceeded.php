<?php

namespace App\AiProviders;

use RuntimeException;

final class ResponseLimitExceeded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Provider health response exceeded the configured size limit.');
    }
}
