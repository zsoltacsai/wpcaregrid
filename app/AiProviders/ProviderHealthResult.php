<?php

namespace App\AiProviders;

final readonly class ProviderHealthResult
{
    public function __construct(
        public string $providerId,
        public ProviderHealthStatus $status,
        public ?string $errorCategory,
        public ?string $generation,
        public bool $persisted,
    ) {}
}
