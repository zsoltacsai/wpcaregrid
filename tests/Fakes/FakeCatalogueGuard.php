<?php

namespace Tests\Fakes;

use App\Owner\IntegrationCredentials;
use App\Pricing\StripeCatalogue\CatalogueGuard;
use App\Pricing\StripeCatalogue\StripeCatalogueGateway;
use App\Pricing\StripeCatalogue\StripeMode;

/**
 * The real guard (every rule about keys, modes, staging and live is the production code) with the real
 * Stripe client replaced by an in-memory one. Records whether a gateway was ever built.
 */
final class FakeCatalogueGuard extends CatalogueGuard
{
    public int $gatewaysBuilt = 0;

    public function __construct(IntegrationCredentials $credentials, private readonly FakeStripeCatalogue $stripe)
    {
        parent::__construct($credentials);
    }

    protected function makeGateway(string $secret, StripeMode $mode): StripeCatalogueGateway
    {
        $this->gatewaysBuilt++;
        $this->stripe->mode = $mode;

        return $this->stripe;
    }
}
