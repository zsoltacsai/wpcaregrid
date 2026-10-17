<?php

namespace App\AiProviders;

final readonly class ProviderProbeResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
        public int $latencyMs,
    ) {}
}
