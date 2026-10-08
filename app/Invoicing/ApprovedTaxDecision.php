<?php

namespace App\Invoicing;

/**
 * Internal boundary value for a future versioned, approved tax decision.
 * Phase 1 does not create or resolve these values from customer/VIES data.
 */
final readonly class ApprovedTaxDecision
{
    /** @param array{net:int,tax:int,gross:int,tax_code:string} $line */
    public function __construct(
        public string $reference,
        public int $version,
        public array $line,
        public string $language,
        public ?string $exchangeRate = null,
        public ?string $exchangeRateBank = null,
    ) {
        if ($reference === '' || $version < 1) {
            throw new \InvalidArgumentException('An approved tax decision reference and version are required.');
        }
    }
}
