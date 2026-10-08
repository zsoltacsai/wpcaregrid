<?php

namespace App\Invoicing\Providers;

use App\Invoicing\NormalizedInvoiceRequest;

interface SyntheticInvoiceTestProvider
{
    public function submitSyntheticTest(NormalizedInvoiceRequest $request): ProviderSubmissionResult;

    public function lookupSyntheticTestByExternalReference(string $externalReference): ProviderLookupResult;
}
