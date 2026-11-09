<?php

namespace App\Pricing\StripeCatalogue;

use RuntimeException;

/** A Stripe catalogue operation that is not allowed here. The code is a fixed identifier, never a message from Stripe. */
final class CatalogueRefused extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
