<?php

namespace App\Invoicing\Providers;

use App\Invoicing\InvoiceValidationResult;
use App\Invoicing\NormalizedInvoiceRequest;

/** Phase 1 intentionally has no submit/issue method. */
interface InvoiceProvider
{
    public function identifier(): string;

    public function validate(NormalizedInvoiceRequest $request): InvoiceValidationResult;

    /** Parse a captured fixture response only; this method performs no I/O. */
    public function parseResponse(array $headers, string $body): array;
}
