<?php

namespace App\Invoicing;

/** Immutable, provider-neutral data prepared from confirmed billing and frozen identities. */
final readonly class NormalizedInvoiceRequest
{
    /** @param array<string, string> $seller @param array<string, string> $buyer */
    public function __construct(
        public string $externalReference,
        public string $currency,
        public int $amountMinor,
        public string $issueDate,
        public string $performanceDate,
        public string $paymentDueDate,
        public ?string $paymentDate,
        public string $paymentMethod,
        public array $seller,
        public array $buyer,
        public string $description,
        public ApprovedTaxDecision $taxDecision,
    ) {}
}
