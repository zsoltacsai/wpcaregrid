<?php

namespace App\Invoicing\Providers;

final readonly class ProviderSubmissionResult
{
    /** @param array<string, int|string|null> $metadata */
    public function __construct(
        public string $outcome,
        public ?string $invoiceNumber = null,
        public ?string $errorCategory = null,
        public array $metadata = [],
        public ?string $pdfContent = null,
    ) {}
}
