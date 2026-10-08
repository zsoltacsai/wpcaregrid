<?php

namespace App\Invoicing\Providers;

use App\Invoicing\NormalizedInvoiceRequest;

interface SyntheticInvoiceTestProvider
{
    /** Validate the exact synthetic request without making a provider request. */
    public function validateSyntheticTest(NormalizedInvoiceRequest $request): bool;

    public function submitSyntheticTest(NormalizedInvoiceRequest $request): ProviderSubmissionResult;

    public function lookupSyntheticTestByExternalReference(string $externalReference): ProviderLookupResult;
}
