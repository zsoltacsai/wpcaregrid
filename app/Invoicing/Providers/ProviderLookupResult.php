<?php

namespace App\Invoicing\Providers;

final readonly class ProviderLookupResult
{
    /** @param array<string, int|string|null> $metadata */
    public function __construct(
        public string $outcome,
        public ?string $invoiceNumber = null,
        public array $metadata = [],
    ) {}
}
