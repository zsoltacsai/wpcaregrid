<?php

namespace App\Invoicing\Providers;

use App\Invoicing\NormalizedInvoiceRequest;

interface InvoiceSubmissionProvider
{
    public function submit(NormalizedInvoiceRequest $request): ProviderSubmissionResult;

    public function lookupByExternalReference(string $externalReference): ProviderLookupResult;
}
