<?php

namespace App\AiProviders;

use RuntimeException;

/** Safe transport failure; intentionally contains no URI, request or response text. */
final class ProviderTransportException extends RuntimeException
{
    public function __construct(
        public readonly ProviderHealthStatus $healthStatus,
        public readonly string $errorCategory,
    ) {
        parent::__construct($errorCategory);
    }
}
